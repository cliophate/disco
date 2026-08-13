<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\EntityNarrative;
use App\Models\ExternalIdentifier;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexItemGuid;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Music\Descriptions\ArtistBiographyEnricher;
use App\Music\Descriptions\TheAudioDbClient;
use App\Music\Descriptions\WikimediaClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ArtistBiographyEnricherTest extends TestCase
{
    public function test_theaudiodb_has_priority_uses_english_fallback_and_is_idempotent(): void
    {
        $this->preparePostgres();
        config()->set('services.wikimedia.language', 'fr');
        $artist = $this->createArtist($this->createLibrary(), 'Fixture Artist', '11111111-1111-4111-8111-111111111111');
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('artist')->twice()->with('11111111-1111-4111-8111-111111111111')->andReturn([
            'idArtist' => '12345',
            'strMusicBrainzID' => '11111111-1111-4111-8111-111111111111',
            'strBiography' => "<p>An attributed artist biography.</p>\n\n\nSecond paragraph.",
        ]);
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $wikimedia->shouldNotReceive('titleForWikidata');
        $wikimedia->shouldNotReceive('introduction');
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldNotReceive('entity');
        $enricher = new ArtistBiographyEnricher($audioDb, $wikimedia, $musicBrainz);

        $this->assertSame('theaudiodb', $enricher->enrich($artist));
        $this->assertSame('theaudiodb', $enricher->enrich($artist));

        $narrative = EntityNarrative::query()->where('entity_id', $artist->id)->sole();
        $this->assertSame('theaudiodb', $narrative->provider_slug);
        $this->assertSame('en', $narrative->language);
        $this->assertSame('ready', $narrative->status);
        $this->assertSame("An attributed artist biography.\n\nSecond paragraph.", $narrative->body);
        $this->assertSame('https://www.theaudiodb.com/artist/12345', $narrative->source_url);
        $this->assertSame('12345', $narrative->external_id);
        $this->assertSame(hash('sha256', $narrative->body), $narrative->content_sha256);
        $this->assertSame('TheAudioDB terms of use', $narrative->license_name);
        $this->assertNotNull($narrative->fetched_at);
    }

    public function test_exact_musicbrainz_wikipedia_relation_is_used_as_fallback(): void
    {
        $this->preparePostgres();
        config()->set('services.wikimedia.language', 'fr');
        $artist = $this->createArtist($this->createLibrary(), 'Fixture Artist', '22222222-2222-4222-8222-222222222222');
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('artist')->once()->andReturn(null);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->once()->with('artist', '22222222-2222-4222-8222-222222222222')->andReturn([
            'relations' => [[
                'type' => 'wikipedia',
                'url' => ['resource' => 'https://en.wikipedia.org/wiki/Fixture_Artist'],
            ]],
        ]);
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $wikimedia->shouldNotReceive('titleForWikidata');
        $wikimedia->shouldReceive('introduction')->once()->with('Fixture_Artist', 'en')->andReturn([
            'language' => 'en',
            'text' => 'An exact-linked Wikipedia biography.',
            'source_url' => 'https://en.wikipedia.org/wiki/Fixture_Artist',
            'external_id' => 'Fixture Artist',
        ]);
        $enricher = new ArtistBiographyEnricher($audioDb, $wikimedia, $musicBrainz);

        $this->assertSame('wikipedia', $enricher->enrich($artist));
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $artist->id,
            'provider_slug' => 'theaudiodb',
            'language' => 'fr',
            'status' => 'missing',
        ]);
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $artist->id,
            'provider_slug' => 'wikipedia',
            'language' => 'en',
            'status' => 'ready',
            'license_name' => 'CC BY-SA 4.0',
        ]);
    }

    public function test_owned_backfill_accepts_only_active_exact_artists_and_obeys_cooldown(): void
    {
        $this->preparePostgres();
        $library = $this->createLibrary();
        $valid = $this->createArtist($library, 'Valid Artist', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd');
        PlexItemGuid::query()->whereHas('item', fn ($query) => $query->where('rating_key', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'))
            ->update(['value' => 'DDDDDDDD-DDDD-4DDD-8DDD-DDDDDDDDDDDD']);
        $this->createArtist($library, 'Inactive Artist', '44444444-4444-4444-8444-444444444444', entityStatus: 'inactive');
        $this->createArtist($library, 'Removed Artist', '55555555-5555-4555-8555-555555555555', removed: true);
        $this->createArtist($library, 'Fuzzy Artist', '66666666-6666-4666-8666-666666666666', method: 'name_similarity');
        $invalid = $this->createArtist($library, 'Invalid Identity', 'not-a-mbid');
        $ambiguous = $this->createArtist($library, 'Ambiguous Identity', '77777777-7777-4777-8777-777777777777');
        ExternalIdentifier::query()->create([
            'entity_id' => $ambiguous->id,
            'namespace' => 'musicbrainz.artist',
            'value' => '88888888-8888-4888-8888-888888888888',
            'status' => 'active',
        ]);
        $ambiguousPlex = $this->createArtist($library, 'Ambiguous Plex Artist', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        PlexItemGuid::query()->create([
            'plex_item_id' => PlexEntityMatch::query()->where('entity_id', $ambiguousPlex->id)->value('plex_item_id'),
            'namespace' => 'mbid',
            'value' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ]);
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('artist')->twice()->with('dddddddd-dddd-4ddd-8ddd-dddddddddddd')->andReturn(null);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->twice()->with('artist', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd')->andReturn(['relations' => []]);
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $wikimedia->shouldNotReceive('titleForWikidata');
        $wikimedia->shouldNotReceive('introduction');
        $enricher = new ArtistBiographyEnricher($audioDb, $wikimedia, $musicBrainz);

        $this->assertSame(['requested' => 1, 'theaudiodb' => 0, 'wikipedia' => 0, 'missing' => 1, 'failed' => 0], $enricher->enrichOwned(10));
        $this->assertSame(0, $enricher->enrichOwned(10)['requested']);
        EntityNarrative::query()->where('entity_id', $valid->id)->update(['fetched_at' => now()->subDays(8)]);
        $this->assertSame(1, $enricher->enrichOwned(10)['missing']);
        $this->assertSame(0, EntityNarrative::query()->where('entity_id', $invalid->id)->count());
        $this->assertSame(0, EntityNarrative::query()->where('entity_id', $ambiguous->id)->count());
        $this->assertSame(0, EntityNarrative::query()->where('entity_id', $ambiguousPlex->id)->count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact MusicBrainz artist identity');
        $enricher->enrich($invalid);
    }

    public function test_command_dispatches_a_bounded_artist_backfill(): void
    {
        $counts = ['requested' => 0, 'theaudiodb' => 0, 'wikipedia' => 0, 'missing' => 0, 'failed' => 0];
        $enricher = Mockery::mock(ArtistBiographyEnricher::class);
        $enricher->shouldReceive('enrichOwned')->once()->with(3, false)->andReturn($counts);
        $this->app->instance(ArtistBiographyEnricher::class, $enricher);

        $this->artisan('disco:artist-biographies', ['--limit' => 3])->assertSuccessful();
    }

    public function test_missing_refresh_marks_cached_biography_stale(): void
    {
        $this->preparePostgres();
        $artist = $this->createArtist($this->createLibrary(), 'Cached Artist', '99999999-9999-4999-8999-999999999999');
        EntityNarrative::query()->create([
            'entity_id' => $artist->id,
            'provider_slug' => 'theaudiodb',
            'kind' => 'description',
            'language' => 'en',
            'status' => 'ready',
            'body' => 'An older cached biography.',
            'source_url' => 'https://www.theaudiodb.com/artist/12345',
            'external_id' => '12345',
            'content_sha256' => hash('sha256', 'An older cached biography.'),
            'license_name' => 'TheAudioDB terms of use',
            'license_url' => 'https://www.theaudiodb.com/docs_terms_of_use.php',
            'fetched_at' => now()->subDays(31),
        ]);
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('artist')->once()->andReturn(null);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->once()->andReturn(['relations' => []]);
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $wikimedia->shouldNotReceive('titleForWikidata');
        $wikimedia->shouldNotReceive('introduction');

        $this->assertNull((new ArtistBiographyEnricher($audioDb, $wikimedia, $musicBrainz))->enrich($artist));
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $artist->id,
            'provider_slug' => 'theaudiodb',
            'status' => 'stale',
            'body' => 'An older cached biography.',
        ]);
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $artist->id,
            'provider_slug' => 'wikipedia',
            'status' => 'missing',
        ]);
    }

    public function test_provider_failure_is_recorded_and_cooled_down(): void
    {
        $this->preparePostgres();
        $artist = $this->createArtist($this->createLibrary(), 'Failing Artist', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('artist')->once()->andThrow(new RuntimeException('Provider unavailable.'));
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $enricher = new ArtistBiographyEnricher($audioDb, $wikimedia, $musicBrainz);

        $this->assertSame(1, $enricher->enrichOwned(1)['failed']);
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $artist->id,
            'provider_slug' => 'narrative_pipeline',
            'status' => 'failed',
        ]);
        $this->assertSame(0, $enricher->enrichOwned(1)['requested']);
    }

    private function preparePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration test; use compose.test.yaml.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than the dedicated disco_test database.');
        }
        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function createLibrary(): PlexLibrary
    {
        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'fixture-machine',
            'machine_identifier_hash' => hash('sha256', 'fixture-machine'),
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        return PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'fixture-library',
            'title' => 'Music',
            'library_type' => 'artist',
            'last_synced_at' => now(),
        ]);
    }

    private function createArtist(PlexLibrary $library, string $name, string $mbid, string $entityStatus = 'active', bool $removed = false, string $method = 'external_id'): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'agent',
            'status' => $entityStatus,
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        Agent::query()->create(['entity_id' => $entity->id, 'agent_type' => 'person']);
        ExternalIdentifier::query()->create([
            'entity_id' => $entity->id,
            'namespace' => 'musicbrainz.artist',
            'value' => $mbid,
            'status' => 'active',
        ]);
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => $mbid,
            'item_type' => 'artist',
            'title' => $name,
            'raw_metadata' => [],
            'last_synced_at' => now(),
            'removed_at' => $removed ? now() : null,
        ]);
        PlexItemGuid::query()->create([
            'plex_item_id' => $item->id,
            'namespace' => 'mbid',
            'value' => $mbid,
        ]);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $entity->id,
            'match_scope' => 'agent',
            'status' => 'confirmed',
            'method' => $method,
            'confidence' => $method === 'external_id' ? 1 : 0.5,
        ]);

        return $entity;
    }
}
