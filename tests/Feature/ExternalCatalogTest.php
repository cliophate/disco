<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\MusicBrainz\MusicBrainzClient;
use App\Music\MusicBrainz\MusicBrainzCreditEnricher;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExternalCatalogTest extends TestCase
{
    public function test_external_search_filters_results_and_materializes_exact_identities_idempotently(): void
    {
        $this->preparePostgres();
        $owned = $this->createOwnedAlbum('11111111-1111-4111-8111-111111111111');
        $canonical = $this->createReleaseGroup('Canonical Ambiguous Album');
        $redirected = $this->createReleaseGroup('Redirected Ambiguous Album');
        $redirected->update(['status' => 'redirected', 'redirect_entity_id' => $canonical->id]);
        ExternalIdentifier::query()->create([
            'entity_id' => $redirected->id,
            'namespace' => 'musicbrainz.release_group',
            'value' => '22222222-2222-4222-8222-222222222222',
            'status' => 'redirected',
        ]);
        $client = Mockery::mock(MusicBrainzClient::class);
        $client->shouldReceive('searchReleaseGroups')->once()->with('Ambiguous Album', 20)->andReturn([
            $this->searchResult('11111111-1111-4111-8111-111111111111', 'Ambiguous Album', 'Album'),
            $this->searchResult('22222222-2222-4222-8222-222222222222', 'Ambiguous Album', 'Album', 'Different artist'),
            $this->searchResult('33333333-3333-4333-8333-333333333333', 'Small Record', 'EP'),
            $this->searchResult('44444444-4444-4444-8444-444444444444', 'Single Result', 'Single'),
            $this->searchResult('55555555-5555-4555-8555-555555555555', 'Compilation Result', 'Album', secondaryTypes: ['Compilation']),
        ]);
        $client->shouldReceive('entity')->twice()->with('release-group', '22222222-2222-4222-8222-222222222222')->andReturn([
            ...$this->searchResult('22222222-2222-4222-8222-222222222222', 'Ambiguous Album', 'Album', 'Different artist'),
            'artist-credit' => [[
                'name' => 'Selected Artist',
                'joinphrase' => '',
                'artist' => ['id' => '66666666-6666-4666-8666-666666666666', 'name' => 'Selected Artist'],
            ]],
            'releases' => [[
                'id' => '77777777-7777-4777-8777-777777777777',
                'title' => 'Ambiguous Album',
                'status' => 'Official',
                'date' => '2024-03-02',
            ]],
        ]);
        $this->app->instance(MusicBrainzClient::class, $client);
        $musicBrainz = Mockery::mock(MusicBrainzEnricher::class);
        $musicBrainz->shouldReceive('enrich')->twice();
        $this->app->instance(MusicBrainzEnricher::class, $musicBrainz);
        $credits = Mockery::mock(MusicBrainzCreditEnricher::class);
        $credits->shouldReceive('enrich')->twice()->andReturn(0);
        $this->app->instance(MusicBrainzCreditEnricher::class, $credits);
        $narratives = Mockery::mock(AlbumNarrativeEnricher::class);
        $narratives->shouldReceive('enrich')->twice()->andReturn(null);
        $this->app->instance(AlbumNarrativeEnricher::class, $narratives);
        $artwork = Mockery::mock(CoverArtArchiveIngestor::class);
        $artwork->shouldReceive('ingest')->twice()->andReturn((new CatalogEntityArtwork)->forceFill(['status' => 'missing']));
        $this->app->instance(CoverArtArchiveIngestor::class, $artwork);

        $this->getJson('/api/v1/external-catalog/search?q=Ambiguous%20Album')->assertUnauthorized();
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $this->actingAs($owner);

        $search = $this->getJson('/api/v1/external-catalog/search?q=Ambiguous%20Album')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.entity_id', $owned->id)
            ->assertJsonPath('data.0.owned', true)
            ->assertJsonPath('data.1.entity_id', $canonical->id)
            ->assertJsonPath('data.2.primary_type', 'EP');
        $this->assertSame(['Ambiguous Album', 'Ambiguous Album', 'Small Record'], collect($search->json('data'))->pluck('title')->all());

        $first = $this->postJson('/api/v1/external-catalog/release-groups/22222222-2222-4222-8222-222222222222')
            ->assertOk()
            ->assertJsonPath('data.id', $canonical->id)
            ->assertJsonPath('data.owned', false)
            ->assertJsonPath('data.enrichment.narrative', 'missing');
        $second = $this->postJson('/api/v1/external-catalog/release-groups/22222222-2222-4222-8222-222222222222')->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ExternalIdentifier::query()->where('namespace', 'musicbrainz.release_group')->where('value', '22222222-2222-4222-8222-222222222222')->count());
        $this->assertDatabaseHas('catalog.external_identifiers', [
            'namespace' => 'musicbrainz.artist',
            'value' => '66666666-6666-4666-8666-666666666666',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('catalog.external_identifiers', [
            'namespace' => 'musicbrainz.release',
            'value' => '77777777-7777-4777-8777-777777777777',
            'status' => 'active',
        ]);
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

    private function createOwnedAlbum(string $mbid): CatalogEntity
    {
        $entity = $this->createReleaseGroup('Owned Album');
        ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => 'musicbrainz.release_group', 'value' => $mbid, 'status' => 'active']);
        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'fixture-machine',
            'machine_identifier_hash' => hash('sha256', 'fixture-machine'),
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'fixture-library',
            'title' => 'Music',
            'library_type' => 'artist',
        ]);
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'owned-album',
            'item_type' => 'album',
            'title' => 'Owned Album',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        Holding::query()->create([
            'release_group_id' => $entity->id,
            'plex_album_item_id' => $item->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);

        return $entity;
    }

    private function createReleaseGroup(string $title): CatalogEntity
    {
        $entity = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => $title, 'sort_name' => $title]);
        ReleaseGroup::query()->create(['entity_id' => $entity->id, 'primary_type' => 'album', 'secondary_types' => [], 'date_precision' => 'unknown']);

        return $entity;
    }

    /** @return array<string, mixed> */
    private function searchResult(string $mbid, string $title, string $type, string $disambiguation = '', array $secondaryTypes = []): array
    {
        return [
            'id' => $mbid,
            'title' => $title,
            'primary-type' => $type,
            'secondary-types' => $secondaryTypes,
            'first-release-date' => '2024-03-02',
            'disambiguation' => $disambiguation,
            'artist-credit' => [['name' => 'Fixture Artist', 'joinphrase' => '', 'artist' => ['id' => '88888888-8888-4888-8888-888888888888', 'name' => 'Fixture Artist']]],
        ];
    }
}
