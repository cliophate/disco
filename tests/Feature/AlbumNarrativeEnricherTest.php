<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\EntityNarrative;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\Descriptions\TheAudioDbClient;
use App\Music\Descriptions\WikimediaClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AlbumNarrativeEnricherTest extends TestCase
{
    public function test_owned_backfill_is_bounded_skips_recent_attempts_and_excludes_unowned_albums(): void
    {
        $this->preparePostgres();
        $library = $this->createLibrary();
        $first = $this->createAlbum($library, 'First Owned Album', '11111111-1111-4111-8111-111111111111', true);
        $second = $this->createAlbum($library, 'Second Owned Album', '22222222-2222-4222-8222-222222222222', true);
        $unowned = $this->createAlbum($library, 'Unowned Album', '33333333-3333-4333-8333-333333333333', false);

        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('album')
            ->times(4)
            ->withArgs(fn (string $mbid): bool => in_array($mbid, [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '44444444-4444-4444-8444-444444444444',
            ], true))
            ->andReturn(null);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')
            ->times(4)
            ->withArgs(fn (string $type, string $mbid): bool => $type === 'release-group' && in_array($mbid, [
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                '44444444-4444-4444-8444-444444444444',
            ], true))
            ->andReturn(['relations' => []]);
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $wikimedia->shouldNotReceive('titleForWikidata');
        $wikimedia->shouldNotReceive('introduction');
        $enricher = new AlbumNarrativeEnricher($audioDb, $wikimedia, $musicBrainz);

        $this->assertSame(1, $enricher->enrichOwned(1)['missing']);
        $this->assertSame(2, DB::table('catalog.entity_narratives')->count());
        $this->assertSame(1, $enricher->enrichOwned(1)['missing']);
        $this->assertSame(4, DB::table('catalog.entity_narratives')->count());
        $this->assertSame(0, $enricher->enrichOwned(1)['requested']);
        DB::table('catalog.entity_narratives')->update(['fetched_at' => now()->subDays(8)]);
        $neverAttempted = $this->createAlbum($library, 'Never Attempted Album', '44444444-4444-4444-8444-444444444444', true);
        $this->assertSame(1, $enricher->enrichOwned(1)['missing']);
        $this->assertSame(2, DB::table('catalog.entity_narratives')->where('entity_id', $neverAttempted->id)->count());
        $this->assertSame(1, $enricher->enrichOwned(1, force: true)['missing']);

        $this->assertSame(2, DB::table('catalog.entity_narratives')->where('entity_id', $first->id)->count());
        $this->assertSame(2, DB::table('catalog.entity_narratives')->where('entity_id', $second->id)->count());
        $this->assertSame(0, DB::table('catalog.entity_narratives')->where('entity_id', $unowned->id)->count());
    }

    public function test_command_dispatches_the_owned_scope(): void
    {
        $counts = ['requested' => 0, 'theaudiodb' => 0, 'wikipedia' => 0, 'missing' => 0, 'failed' => 0];
        $enricher = Mockery::mock(AlbumNarrativeEnricher::class);
        $enricher->shouldReceive('enrichOwned')->once()->with(3, false)->andReturn($counts);
        $this->app->instance(AlbumNarrativeEnricher::class, $enricher);

        $this->artisan('disco:album-narratives', ['--scope' => 'owned', '--limit' => 3])
            ->assertSuccessful();
    }

    public function test_beyond_backfill_fills_its_budget_after_skipping_recent_attempts(): void
    {
        $this->preparePostgres();
        $library = $this->createLibrary();
        $recent = $this->createAlbum($library, 'Recently Attempted Album', '66666666-6666-4666-8666-666666666666', false);
        $eligible = $this->createAlbum($library, 'Eligible Album', '77777777-7777-4777-8777-777777777777', false);
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $run = RecommendationRun::query()->create([
            'user_id' => $user->id,
            'intent' => 'beyond_library',
            'input' => [],
            'algorithm_version' => 'fixture-v1',
            'configuration_hash' => hash('sha256', 'fixture'),
            'random_seed' => 1,
            'catalog_version' => 'fixture',
            'status' => 'completed',
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        foreach ([$recent, $eligible] as $index => $entity) {
            RecommendationItem::query()->create([
                'run_id' => $run->id,
                'entity_id' => $entity->id,
                'rank' => $index + 1,
                'score' => 1 - ($index / 10),
                'component_scores' => [],
                'eligibility' => ['scope' => 'external'],
                'module_type' => 'beyond-library',
                'explanation_text' => 'Fixture recommendation.',
                'explanation_version' => 'reasons-v1',
            ]);
        }
        EntityNarrative::query()->create([
            'entity_id' => $recent->id,
            'provider_slug' => 'theaudiodb',
            'kind' => 'description',
            'language' => 'en',
            'status' => 'missing',
            'fetched_at' => now(),
        ]);
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('album')->once()->with('77777777-7777-4777-8777-777777777777')->andReturn(null);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->once()->with('release-group', '77777777-7777-4777-8777-777777777777')->andReturn(['relations' => []]);
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $wikimedia->shouldNotReceive('titleForWikidata');
        $wikimedia->shouldNotReceive('introduction');

        $counts = (new AlbumNarrativeEnricher($audioDb, $wikimedia, $musicBrainz))->enrichLatestBeyond(1);

        $this->assertSame(1, $counts['requested']);
        $this->assertSame(1, $counts['missing']);
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $eligible->id,
            'provider_slug' => 'theaudiodb',
            'status' => 'missing',
        ]);
    }

    public function test_provider_failure_is_recorded_and_cooled_down(): void
    {
        $this->preparePostgres();
        $library = $this->createLibrary();
        $album = $this->createAlbum($library, 'Failing Album', '55555555-5555-4555-8555-555555555555', true);
        $audioDb = Mockery::mock(TheAudioDbClient::class);
        $audioDb->shouldReceive('album')->once()->andThrow(new RuntimeException('Provider unavailable.'));
        $wikimedia = Mockery::mock(WikimediaClient::class);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $enricher = new AlbumNarrativeEnricher($audioDb, $wikimedia, $musicBrainz);

        $this->assertSame(1, $enricher->enrichOwned(1)['failed']);
        $this->assertDatabaseHas('catalog.entity_narratives', [
            'entity_id' => $album->id,
            'provider_slug' => 'narrative_pipeline',
            'status' => 'failed',
        ]);
        EntityNarrative::query()->create([
            'entity_id' => $album->id,
            'provider_slug' => 'theaudiodb',
            'kind' => 'description',
            'language' => 'en',
            'status' => 'ready',
            'body' => 'An older cached description.',
            'source_url' => 'https://www.theaudiodb.com/album/12345',
            'external_id' => '12345',
            'content_sha256' => hash('sha256', 'An older cached description.'),
            'license_name' => 'TheAudioDB terms of use',
            'license_url' => 'https://www.theaudiodb.com/docs_terms_of_use.php',
            'fetched_at' => now()->subDays(31),
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

    private function createAlbum(PlexLibrary $library, string $title, string $mbid, bool $owned): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => $title,
            'sort_name' => $title,
        ]);
        ReleaseGroup::query()->create([
            'entity_id' => $entity->id,
            'primary_type' => 'album',
            'secondary_types' => [],
            'date_precision' => 'unknown',
        ]);
        ExternalIdentifier::query()->create([
            'entity_id' => $entity->id,
            'namespace' => 'musicbrainz.release_group',
            'value' => $mbid,
            'status' => 'active',
        ]);
        if ($owned) {
            $item = PlexItem::query()->create([
                'plex_library_id' => $library->id,
                'rating_key' => $mbid,
                'item_type' => 'album',
                'title' => $title,
                'raw_metadata' => [],
                'last_synced_at' => now(),
            ]);
            Holding::query()->create([
                'release_group_id' => $entity->id,
                'plex_album_item_id' => $item->id,
                'ownership_type' => 'digital',
                'is_primary_playback_copy' => true,
            ]);
        }

        return $entity;
    }
}
