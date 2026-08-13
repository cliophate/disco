<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\RecommendationEvidence;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Discovery\DailyFeatureSelector;
use App\Music\Discovery\HomeDiscoveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class HomeFeatureRotationTest extends TestCase
{
    public function test_discover_projects_a_distinct_paginated_feed_pinned_to_its_edition(): void
    {
        $this->preparePostgres();
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $owned = $this->createOwnedAlbum();
        $beyond = $this->createBeyondAlbum($user);
        [$ownedDay, $beyondDay] = $this->daysForBothScopes($owned->id, $beyond->id);
        $this->actingAs($user);

        try {
            CarbonImmutable::setTestNow($ownedDay->setTime(12, 0));
            $home = $this->getJson('/api/v1/home')->assertOk();
            $editionId = $home->json('meta.edition_id');
            $waiting = app(HomeDiscoveryService::class)->lens('waiting', $user->id, $ownedDay->toDateString());
            $this->assertSame($owned->id, data_get($waiting, 'items.0.album.id'));
            $first = $this->getJson('/api/v1/discover?page[number]=1&page[size]=1')
                ->assertOk()
                ->assertJsonPath('meta.edition_id', $editionId)
                ->assertJsonPath('meta.total', 2)
                ->assertJsonPath('data.0.id', "album:{$owned->id}")
                ->assertJsonPath('data.0.presentation', 'feature')
                ->assertJsonPath('data.0.span', 'feature')
                ->assertJsonMissingPath('data.sections');
            $this->assertNotNull($first->json('links.next'));

            CarbonImmutable::setTestNow($beyondDay->setTime(12, 0));
            $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.feature.album.id', $beyond->id);
            $this->getJson('/api/v1/discover?page[number]=2&page[size]=1&edition_id='.$editionId)
                ->assertOk()
                ->assertJsonPath('meta.edition_id', $editionId)
                ->assertJsonPath('data.0.id', "album:{$beyond->id}")
                ->assertJsonPath('data.0.presentation', 'editorial')
                ->assertJsonPath('links.next', null);
            $this->assertGreaterThan(0, DB::table('discovery.recommendation_impressions')->where('surface', 'home')->count());
            $this->assertSame(2, DB::table('discovery.recommendation_impressions')->where('surface', 'discover')->count());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_mixed_owned_and_beyond_pool_rotates_daily_and_persists_the_feature_first(): void
    {
        $this->preparePostgres();
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $owned = $this->createOwnedAlbum();
        $beyond = $this->createBeyondAlbum($user);
        [$ownedDay, $beyondDay] = $this->daysForBothScopes($owned->id, $beyond->id);
        $this->actingAs($user);

        try {
            CarbonImmutable::setTestNow($ownedDay->setTime(12, 0));
            $ownedHome = $this->getJson('/api/v1/home')
                ->assertOk()
                ->assertJsonPath('data.feature.album.id', $owned->id)
                ->assertJsonPath('data.feature.album.owned', true)
                ->assertJsonPath('data.sections.0.type', 'beyond-library')
                ->assertJsonPath('data.sections.0.items.0.album.id', $beyond->id);
            $this->getJson('/api/v1/home')
                ->assertOk()
                ->assertJsonPath('meta.edition_id', $ownedHome->json('meta.edition_id'))
                ->assertJsonPath('data.feature.album.id', $owned->id);
            $ownedRunId = DB::table('discovery.home_editions')->where('id', $ownedHome->json('meta.edition_id'))->value('recommendation_run_id');
            $this->assertSame([$owned->id, $beyond->id], DB::table('discovery.recommendation_items')->where('run_id', $ownedRunId)->orderBy('rank')->pluck('entity_id')->all());

            CarbonImmutable::setTestNow($beyondDay->setTime(12, 0));
            $beyondHome = $this->getJson('/api/v1/home')
                ->assertOk()
                ->assertJsonPath('data.feature.album.id', $beyond->id)
                ->assertJsonPath('data.feature.album.owned', false)
                ->assertJsonPath('data.feature.lens', 'Beyond your library');
            $this->assertNotSame($ownedHome->json('meta.edition_id'), $beyondHome->json('meta.edition_id'));
            $sectionAlbumIds = collect($beyondHome->json('data.sections'))->pluck('items')->flatten(1)->pluck('album.id');
            $this->assertFalse($sectionAlbumIds->contains($beyond->id));
            $beyondRunId = DB::table('discovery.home_editions')->where('id', $beyondHome->json('meta.edition_id'))->value('recommendation_run_id');
            $firstItem = RecommendationItem::query()->where('run_id', $beyondRunId)->orderBy('rank')->firstOrFail();
            $this->assertSame($beyond->id, $firstItem->entity_id);
            $this->assertSame('beyond', $firstItem->eligibility['scope']);

            $this->getJson("/api/v1/albums/{$owned->id}")->assertOk();
            $this->getJson("/api/v1/albums/{$beyond->id}")->assertOk();
        } finally {
            CarbonImmutable::setTestNow();
        }
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

    private function createOwnedAlbum(): CatalogEntity
    {
        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'fixture-machine',
            'machine_identifier_hash' => hash('sha256', 'fixture-machine'),
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'fixture-library',
            'title' => 'Music',
            'library_type' => 'artist',
            'last_synced_at' => now(),
        ]);
        $entity = $this->createReleaseGroup('Owned Album', '11111111-1111-4111-8111-111111111111');
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'owned-album',
            'item_type' => 'album',
            'title' => 'Owned Album',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $entity->id,
            'match_scope' => 'release_group',
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);
        Holding::query()->create([
            'release_group_id' => $entity->id,
            'plex_album_item_id' => $item->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);

        return $entity;
    }

    private function createBeyondAlbum(User $user): CatalogEntity
    {
        $entity = $this->createReleaseGroup('Beyond Album', '22222222-2222-4222-8222-222222222222');
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
        $item = RecommendationItem::query()->create([
            'run_id' => $run->id,
            'entity_id' => $entity->id,
            'rank' => 1,
            'score' => 0.9,
            'component_scores' => [],
            'eligibility' => ['scope' => 'external'],
            'module_type' => 'beyond-library',
            'explanation_text' => 'Recommended beyond the library.',
            'explanation_version' => 'reasons-v1',
        ]);
        RecommendationEvidence::query()->create([
            'recommendation_item_id' => $item->id,
            'evidence_type' => 'listenbrainz_recommendation',
            'subject_entity_id' => $entity->id,
            'predicate' => 'discovery.reason.listenbrainz_recommendation',
            'source_slug' => 'listenbrainz',
            'weight' => 1,
            'display_text' => 'Recommended by ListenBrainz.',
        ]);

        return $entity;
    }

    private function createReleaseGroup(string $title, string $mbid): CatalogEntity
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

        return $entity;
    }

    /** @return array{CarbonImmutable,CarbonImmutable} */
    private function daysForBothScopes(string $ownedId, string $beyondId): array
    {
        $selector = app(DailyFeatureSelector::class);
        $candidates = [
            ['album' => ['id' => $ownedId, 'identity_status' => 'confirmed'], 'scope' => 'owned'],
            ['album' => ['id' => $beyondId, 'identity_status' => 'confirmed'], 'scope' => 'beyond'],
        ];
        $ownedDay = $beyondDay = null;
        for ($day = CarbonImmutable::parse('2026-01-01'); $day->year === 2026 && ($ownedDay === null || $beyondDay === null); $day = $day->addDay()) {
            $scope = $selector->select($candidates, $day->toDateString())['scope'] ?? null;
            $ownedDay ??= $scope === 'owned' ? $day : null;
            $beyondDay ??= $scope === 'beyond' ? $day : null;
        }
        if ($ownedDay === null || $beyondDay === null) {
            throw new RuntimeException('Fixture dates did not exercise both feature scopes.');
        }

        return [$ownedDay, $beyondDay];
    }
}
