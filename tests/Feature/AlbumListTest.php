<?php

namespace Tests\Feature;

use App\Models\AlbumListItem;
use App\Models\CatalogEntity;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Discovery\BeyondLibraryDiscoveryService;
use App\Music\Discovery\HomeDiscoveryService;
use App\Music\Discovery\HomeProjectionVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AlbumListTest extends TestCase
{
    public function test_album_list_is_private_idempotent_redirect_safe_and_tracks_current_ownership(): void
    {
        $this->preparePostgres();
        Http::preventStrayRequests();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $external = $this->createAlbum('External Album');
        $owned = $this->createAlbum('Owned Album');
        $this->hold($owned);
        $versionBefore = app(HomeProjectionVersion::class)->current($owner->id);

        $this->patchJson("/api/v1/albums/{$external->id}/list-state", ['status' => 'want_to_listen'])->assertUnauthorized();
        $response = $this->actingAs($owner)->patchJson("/api/v1/albums/{$external->id}/list-state", [
            'status' => 'want_to_listen', 'note' => 'Try the mono mix', 'source' => 'Recommended by Alex',
        ])->assertOk()->assertJsonPath('data.album_id', $external->id)->assertJsonPath('data.status', 'want_to_listen');
        $changedAt = $response->json('data.state_changed_at');
        $this->patchJson("/api/v1/albums/{$external->id}/list-state", ['status' => 'want_to_listen'])
            ->assertOk()->assertJsonPath('data.state_changed_at', $changedAt)->assertJsonPath('data.note', 'Try the mono mix');
        $this->assertSame(1, AlbumListItem::query()->count());
        $this->assertNotSame($versionBefore, app(HomeProjectionVersion::class)->current($owner->id));

        $this->getJson("/api/v1/albums/{$external->id}")->assertOk()
            ->assertJsonPath('data.list_state.note', 'Try the mono mix')->assertJsonPath('data.owned', false);
        $this->patchJson("/api/v1/albums/{$owned->id}/list-state", ['status' => 'want_to_listen'])->assertOk();
        $this->getJson('/api/v1/want-to-listen?ownership=owned')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->id)->assertJsonPath('meta.sort', 'name');
        $this->getJson('/api/v1/want-to-listen?ownership=outside')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $external->id);

        $this->patchJson("/api/v1/albums/{$external->id}/list-state", ['status' => 'listened', 'note' => null])
            ->assertOk()->assertJsonPath('data.status', 'listened')->assertJsonPath('data.note', null);
        $this->getJson('/api/v1/want-to-listen?status=listened')->assertOk()->assertJsonPath('meta.filters.listened', 1)->assertJsonPath('data.0.id', $external->id);
        $this->deleteJson("/api/v1/albums/{$external->id}/list-state")->assertNoContent();
        $this->deleteJson("/api/v1/albums/{$external->id}/list-state")->assertNoContent();
        $this->getJson('/api/v1/want-to-listen?status=removed')->assertOk()->assertJsonPath('data.0.list_state.source', 'Recommended by Alex');
        $this->patchJson("/api/v1/albums/{$external->id}/list-state", ['status' => 'want_to_listen'])->assertOk()->assertJsonPath('data.removed_at', null);

        $old = $this->createAlbum('Old Album');
        AlbumListItem::query()->create(['user_id' => $owner->id, 'release_group_entity_id' => $old->id, 'status' => 'want_to_listen', 'wanted_at' => now(), 'state_changed_at' => now()]);
        $old->update(['status' => 'redirected', 'redirect_entity_id' => $external->id]);
        $this->patchJson("/api/v1/albums/{$old->id}/list-state", ['status' => 'listened'])->assertOk()->assertJsonPath('data.album_id', $external->id);
        $this->assertSame(2, AlbumListItem::query()->where('user_id', $owner->id)->count());
        $this->getJson("/api/v1/albums/{$old->id}")->assertOk()->assertJsonPath('data.id', $external->id)->assertJsonPath('data.list_state.status', 'listened');

        $run = RecommendationRun::query()->create(['user_id' => $owner->id, 'intent' => 'beyond_library', 'input' => [], 'algorithm_version' => 'fixture-v1', 'configuration_hash' => hash('sha256', 'fixture'), 'random_seed' => 1, 'catalog_version' => 'fixture', 'status' => 'completed', 'generated_at' => now(), 'expires_at' => now()->addDay()]);
        RecommendationItem::query()->create(['run_id' => $run->id, 'entity_id' => $external->id, 'rank' => 1, 'score' => 1, 'component_scores' => [], 'eligibility' => ['scope' => 'external'], 'module_type' => 'beyond-library', 'explanation_text' => 'Fixture.', 'explanation_version' => 'fixture-v1']);
        $this->assertNull(app(BeyondLibraryDiscoveryService::class)->forUser($owner->id));
        $this->patchJson("/api/v1/albums/{$owned->id}/list-state", ['status' => 'listened'])->assertOk();
        $home = app(HomeDiscoveryService::class)->build($owner->id);
        $homeIds = collect([data_get($home, 'feature.album.id')])->merge(collect(data_get($home, 'sections', []))->pluck('items')->flatten(1)->pluck('album.id'));
        $this->assertNotContains($owned->id, $homeIds);
    }

    private function createAlbum(string $name): CatalogEntity
    {
        $entity = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        ReleaseGroup::query()->create(['entity_id' => $entity->id, 'primary_type' => 'Album', 'secondary_types' => []]);

        return $entity;
    }

    private function hold(CatalogEntity $album): void
    {
        $server = PlexServer::query()->create(['name' => 'Plex', 'machine_identifier' => 'machine', 'machine_identifier_hash' => hash('sha256', 'machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '1', 'section_uuid' => 'library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $item = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => 'album', 'item_type' => 'album', 'title' => $album->canonical_name, 'sort_title' => $album->sort_name, 'raw_metadata' => [], 'last_synced_at' => now()]);
        PlexEntityMatch::query()->create(['plex_item_id' => $item->id, 'entity_id' => $album->id, 'match_scope' => 'release_group', 'status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1]);
        Holding::query()->create(['release_group_id' => $album->id, 'plex_album_item_id' => $item->id, 'ownership_type' => 'digital', 'is_primary_playback_copy' => true]);
    }

    private function preparePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration test; use compose.test.yaml.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than disco_test.');
        }
        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
