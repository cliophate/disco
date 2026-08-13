<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\EntityResolution;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexItemGuid;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\User;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ReleaseEditionMigrationTest extends TestCase
{
    public function test_legacy_release_identifiers_become_editions_without_changing_album_identity(): void
    {
        $this->preparePostgres();

        $releaseGroup = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => 'Legacy Album',
            'sort_name' => 'Legacy Album',
        ]);
        ReleaseGroup::query()->create(['entity_id' => $releaseGroup->id, 'primary_type' => 'album']);
        EntityMetadata::query()->create([
            'entity_id' => $releaseGroup->id,
            'source_provider' => 'musicbrainz',
            'country_code' => 'GB',
            'attributes' => [
                'basis_release_mbid' => 'ABCDEFAB-CDEF-4ABC-8DEF-ABCDEFABCDEF',
                'edition_date' => '2021-09-10',
                'edition_status' => 'Official',
                'release_group_mbid' => 'BCDEFABC-DEFA-4BCD-8EFA-BCDEFABCDEFA',
            ],
            'enriched_at' => now(),
        ]);
        ExternalIdentifier::query()->create([
            'entity_id' => $releaseGroup->id,
            'namespace' => 'musicbrainz.release',
            'value' => 'ABCDEFAB-CDEF-4ABC-8DEF-ABCDEFABCDEF',
            'status' => 'active',
        ]);
        $server = PlexServer::query()->create([
            'name' => 'Legacy Plex',
            'machine_identifier' => 'legacy-machine',
            'machine_identifier_hash' => hash('sha256', 'legacy-machine'),
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'legacy-library',
            'title' => 'Music',
            'library_type' => 'artist',
        ]);
        $album = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'album-1',
            'item_type' => 'album',
            'title' => 'Legacy Album',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        PlexItemGuid::query()->create([
            'plex_item_id' => $album->id,
            'namespace' => 'mbid',
            'value' => 'ABCDEFAB-CDEF-4ABC-8DEF-ABCDEFABCDEF',
        ]);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $album->id,
            'entity_id' => $releaseGroup->id,
            'match_scope' => 'release_group',
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);
        Holding::query()->create([
            'release_group_id' => $releaseGroup->id,
            'plex_album_item_id' => $album->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);

        $migration = require database_path('migrations/2026_07_22_000600_activate_release_editions.php');
        DB::transaction(fn () => $migration->up());

        $releaseId = DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release')
            ->value('entity_id');
        $this->assertNotSame($releaseGroup->id, $releaseId);
        $this->assertSame('abcdefab-cdef-4abc-8def-abcdefabcdef', DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release')
            ->value('value'));
        $this->assertSame('release', DB::table('catalog.entities')->where('id', $releaseId)->value('kind'));
        $this->assertSame($releaseGroup->id, DB::table('catalog.releases')->where('entity_id', $releaseId)->value('release_group_id'));
        $this->assertSame('day', DB::table('catalog.releases')->where('entity_id', $releaseId)->value('date_precision'));
        $this->assertSame('GB', DB::table('catalog.entity_metadata')->where('entity_id', $releaseId)->value('country_code'));
        $this->assertSame($releaseGroup->id, DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release_group')
            ->value('entity_id'));
        $this->assertSame($releaseId, DB::table('library.holdings')->value('release_id'));
        $this->assertSame($releaseId, DB::table('library.plex_entity_matches')
            ->where('match_scope', 'release')
            ->where('status', 'confirmed')
            ->value('entity_id'));

        DB::transaction(fn () => $migration->up());
        $this->assertSame(1, DB::table('catalog.releases')->count());
        $this->assertSame(1, DB::table('catalog.external_identifiers')->where('namespace', 'musicbrainz.release_group')->count());
    }

    public function test_legacy_editions_with_one_release_group_converge_on_the_active_primary_album(): void
    {
        $this->preparePostgres();

        $releaseGroupMbid = '33333333-3333-4333-8333-333333333333';
        $legacyGroups = collect([
            ['release_mbid' => '11111111-1111-4111-8111-111111111111', 'removed' => true, 'primary' => false],
            ['release_mbid' => '22222222-2222-4222-8222-222222222222', 'removed' => false, 'primary' => true],
        ])->map(function (array $edition) use ($releaseGroupMbid): array {
            $group = CatalogEntity::query()->create([
                'kind' => 'release_group',
                'status' => 'active',
                'canonical_name' => 'Two Editions',
                'sort_name' => 'Two Editions',
            ]);
            ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'album']);
            EntityMetadata::query()->create([
                'entity_id' => $group->id,
                'source_provider' => 'musicbrainz',
                'attributes' => [
                    'basis_release_mbid' => $edition['release_mbid'],
                    'release_group_mbid' => $releaseGroupMbid,
                ],
                'enriched_at' => now(),
            ]);
            ExternalIdentifier::query()->create([
                'entity_id' => $group->id,
                'namespace' => 'musicbrainz.release',
                'value' => $edition['release_mbid'],
                'status' => 'active',
            ]);

            return $edition + ['group' => $group];
        });
        $server = PlexServer::query()->create([
            'name' => 'Legacy Plex',
            'machine_identifier' => 'legacy-editions-machine',
            'machine_identifier_hash' => hash('sha256', 'legacy-editions-machine'),
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'legacy-editions-library',
            'title' => 'Music',
            'library_type' => 'artist',
        ]);
        $provider = SourceProvider::query()->create([
            'slug' => 'plex',
            'display_name' => 'Plex',
            'enabled' => true,
            'policy' => [],
        ]);
        foreach ($legacyGroups as $index => $edition) {
            $album = PlexItem::query()->create([
                'plex_library_id' => $library->id,
                'rating_key' => "album-{$index}",
                'item_type' => 'album',
                'title' => 'Two Editions',
                'raw_metadata' => [],
                'last_synced_at' => now(),
                'removed_at' => $edition['removed'] ? now() : null,
            ]);
            PlexItemGuid::query()->create([
                'plex_item_id' => $album->id,
                'namespace' => 'mbid',
                'value' => $edition['release_mbid'],
            ]);
            PlexEntityMatch::query()->create([
                'plex_item_id' => $album->id,
                'entity_id' => $edition['group']->id,
                'match_scope' => 'release_group',
                'status' => 'confirmed',
                'method' => 'external_id',
                'confidence' => 1,
            ]);
            Holding::query()->create([
                'release_group_id' => $edition['group']->id,
                'plex_album_item_id' => $album->id,
                'ownership_type' => 'digital',
                'is_primary_playback_copy' => $edition['primary'],
            ]);
            $sourceObject = SourceObject::query()->create([
                'provider_id' => $provider->id,
                'object_type' => 'album',
                'external_id' => "{$server->id}:1:album-{$index}",
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            EntityResolution::query()->create([
                'source_object_id' => $sourceObject->id,
                'entity_id' => $edition['group']->id,
                'resolution_scope' => 'release_group',
                'status' => 'confirmed',
                'method' => 'external_id',
                'confidence' => 1,
                'algorithm_version' => 'plex-v1',
                'evidence' => ['plex_item_id' => $album->id],
            ]);
        }
        $preexistingRelease = CatalogEntity::query()->create([
            'kind' => 'release',
            'status' => 'active',
            'canonical_name' => 'Unmatched legacy edition',
            'sort_name' => 'Unmatched legacy edition',
        ]);
        Release::query()->create([
            'entity_id' => $preexistingRelease->id,
            'release_group_id' => $legacyGroups[0]['group']->id,
            'status' => 'unknown',
        ]);

        $migration = require database_path('migrations/2026_07_22_000600_activate_release_editions.php');
        DB::transaction(fn () => $migration->up());

        $canonicalGroupId = $legacyGroups[1]['group']->id;
        $redirectedGroupId = $legacyGroups[0]['group']->id;
        $this->assertSame($canonicalGroupId, DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release_group')
            ->where('value', $releaseGroupMbid)
            ->value('entity_id'));
        $this->assertSame(3, DB::table('catalog.releases')->where('release_group_id', $canonicalGroupId)->count());
        $this->assertSame($canonicalGroupId, DB::table('catalog.releases')->where('entity_id', $preexistingRelease->id)->value('release_group_id'));
        $this->assertSame(2, DB::table('library.holdings')->where('release_group_id', $canonicalGroupId)->count());
        $this->assertSame(2, DB::table('library.holdings')->whereNotNull('release_id')->count());
        $this->assertSame(
            ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'],
            DB::table('library.holdings as holdings')
                ->join('catalog.external_identifiers as identifiers', 'identifiers.entity_id', '=', 'holdings.release_id')
                ->orderBy('identifiers.value')
                ->pluck('identifiers.value')
                ->all(),
        );
        $this->assertSame(1, DB::table('library.holdings')->where('release_group_id', $canonicalGroupId)->where('is_primary_playback_copy', true)->count());
        $this->assertSame(1, DB::table('library.holdings as holdings')
            ->join('library.plex_items as items', 'items.id', '=', 'holdings.plex_album_item_id')
            ->whereNull('items.removed_at')
            ->where('holdings.is_primary_playback_copy', true)
            ->count());
        $this->assertSame(0, DB::table('library.holdings as holdings')
            ->join('library.plex_items as items', 'items.id', '=', 'holdings.plex_album_item_id')
            ->whereNotNull('items.removed_at')
            ->where('holdings.is_primary_playback_copy', true)
            ->count());
        $this->assertSame(2, DB::table('library.plex_entity_matches')
            ->where('entity_id', $canonicalGroupId)
            ->where('match_scope', 'release_group')
            ->where('status', 'confirmed')
            ->count());
        $this->assertSame(2, DB::table('source.entity_resolutions')
            ->where('entity_id', $canonicalGroupId)
            ->where('resolution_scope', 'release_group')
            ->where('status', 'confirmed')
            ->count());
        $this->assertSame(2, DB::table('source.entity_resolutions as resolutions')
            ->join('catalog.entities as entities', 'entities.id', '=', 'resolutions.entity_id')
            ->where('resolutions.resolution_scope', 'release')
            ->where('resolutions.status', 'confirmed')
            ->where('entities.kind', 'release')
            ->count());
        $this->assertSame('redirected', DB::table('catalog.entities')->where('id', $redirectedGroupId)->value('status'));
        $this->assertSame($canonicalGroupId, DB::table('catalog.entities')->where('id', $redirectedGroupId)->value('redirect_entity_id'));

        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'migration-owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        $this->actingAs($owner)
            ->getJson("/api/v1/albums/{$redirectedGroupId}")
            ->assertOk()
            ->assertJsonPath('data.id', $canonicalGroupId);
        PlexItem::query()->where('rating_key', 'album-0')->update(['removed_at' => null]);
        $this->getJson('/api/v1/library/albums')
            ->assertOk()
            ->assertJsonPath('data.0.open_in_plex_status', 'choice-required');
    }

    public function test_musicbrainz_converges_two_editions_on_one_canonical_release_group(): void
    {
        $this->preparePostgres();
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);

        $canonicalGroup = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => 'Canonical Album',
            'sort_name' => 'Canonical Album',
        ]);
        $provisionalGroup = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => 'Second Edition',
            'sort_name' => 'Second Edition',
        ]);
        ReleaseGroup::query()->create(['entity_id' => $canonicalGroup->id, 'primary_type' => 'album']);
        ReleaseGroup::query()->create(['entity_id' => $provisionalGroup->id, 'primary_type' => 'album']);
        ExternalIdentifier::query()->create([
            'entity_id' => $canonicalGroup->id,
            'namespace' => 'musicbrainz.release_group',
            'value' => '33333333-3333-4333-8333-333333333333',
            'status' => 'active',
        ]);
        $releaseEntity = CatalogEntity::query()->create([
            'kind' => 'release',
            'status' => 'active',
            'canonical_name' => 'Second Edition',
            'sort_name' => 'Second Edition',
        ]);
        Release::query()->create([
            'entity_id' => $releaseEntity->id,
            'release_group_id' => $provisionalGroup->id,
            'status' => 'unknown',
        ]);
        $releaseIdentifier = ExternalIdentifier::query()->create([
            'entity_id' => $releaseEntity->id,
            'namespace' => 'musicbrainz.release',
            'value' => '77777777-7777-4777-8777-777777777777',
            'status' => 'active',
        ]);
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
        $album = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'album-2',
            'item_type' => 'album',
            'title' => 'Second Edition',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        foreach ([[$provisionalGroup->id, 'release_group'], [$releaseEntity->id, 'release']] as [$entityId, $scope]) {
            PlexEntityMatch::query()->create([
                'plex_item_id' => $album->id,
                'entity_id' => $entityId,
                'match_scope' => $scope,
                'status' => 'confirmed',
                'method' => $scope === 'release' ? 'external_id' : 'release_parent',
                'confidence' => 1,
            ]);
        }
        Holding::query()->create([
            'release_group_id' => $provisionalGroup->id,
            'release_id' => $releaseEntity->id,
            'plex_album_item_id' => $album->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);
        Http::fake([
            'https://musicbrainz.test/ws/2/release/77777777-7777-4777-8777-777777777777*' => Http::response([
                'id' => '77777777-7777-4777-8777-777777777777',
                'title' => 'Second Edition',
                'status' => 'Official',
                'country' => 'GB',
                'date' => '2022-01-02',
                'release-group' => [
                    'id' => '33333333-3333-4333-8333-333333333333',
                    'primary-type' => 'Album',
                    'first-release-date' => '2021-09-10',
                ],
                'label-info' => [[
                    'label' => ['name' => 'Edition Label'],
                    'catalog-number' => 'ED-2',
                ]],
                'media' => [[
                    'position' => 1,
                    'format' => 'Digital Media',
                    'tracks' => [[
                        'position' => 1,
                        'number' => '1',
                        'title' => 'Edition Track',
                        'length' => 180000,
                    ]],
                ]],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        app(MusicBrainzEnricher::class)->enrich($releaseIdentifier);

        $this->assertSame($canonicalGroup->id, Release::query()->findOrFail($releaseEntity->id)->release_group_id);
        $this->assertSame($canonicalGroup->id, Holding::query()->firstOrFail()->release_group_id);
        $this->assertSame($releaseEntity->id, Holding::query()->firstOrFail()->release_id);
        $this->assertSame($canonicalGroup->id, PlexEntityMatch::query()
            ->where('plex_item_id', $album->id)
            ->where('match_scope', 'release_group')
            ->where('status', 'confirmed')
            ->value('entity_id'));
        $this->assertSame('redirected', CatalogEntity::query()->findOrFail($provisionalGroup->id)->status);
        $this->assertSame($canonicalGroup->id, CatalogEntity::query()->findOrFail($provisionalGroup->id)->redirect_entity_id);

        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        $this->actingAs($owner);
        $this->getJson("/api/v1/albums/{$provisionalGroup->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $canonicalGroup->id)
            ->assertJsonPath('data.basis_release_id', $releaseEntity->id)
            ->assertJsonPath('data.labels.0.name', 'Edition Label');
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
}
