<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ArtistFollow;
use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Discovery\ArtistSeedService;
use App\Music\Discovery\HomeProjectionVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ArtistFollowTest extends TestCase
{
    public function test_explicit_and_implicit_artist_seeds_are_private_idempotent_and_redirect_safe(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $external = $this->createAgent('External Artist');
        $versionBefore = app(HomeProjectionVersion::class)->current($owner->id);

        $this->putJson("/api/v1/artists/{$external->id}/follow")->assertUnauthorized();
        $this->actingAs($owner)->putJson("/api/v1/artists/{$external->id}/follow")
            ->assertOk()->assertJsonPath('data.artist_id', $external->id)
            ->assertJsonPath('data.explicit', true)->assertJsonPath('data.implicit', false);
        $this->putJson("/api/v1/artists/{$external->id}/follow")->assertOk();
        $this->assertSame(1, ArtistFollow::query()->count());
        $this->getJson("/api/v1/artists/{$external->id}")->assertOk()
            ->assertJsonPath('data.id', $external->id)
            ->assertJsonPath('data.follow_state.explicit', true)
            ->assertJsonCount(0, 'data.albums');
        $this->assertNotSame($versionBefore, app(HomeProjectionVersion::class)->current($owner->id));
        $this->assertDatabaseCount('library.holdings', 0);

        $implicit = $this->createHeldArtist('Held Artist');
        $state = app(ArtistSeedService::class)->state($owner->id, $implicit->id);
        $this->assertTrue($state['implicit']);
        $this->assertFalse($state['explicit']);
        $this->assertSame(1, ArtistFollow::query()->count());

        $old = $this->createAgent('Old Artist');
        ArtistFollow::query()->create(['user_id' => $owner->id, 'artist_entity_id' => $old->id]);
        $old->update(['status' => 'redirected', 'redirect_entity_id' => $external->id]);
        $this->putJson("/api/v1/artists/{$old->id}/follow")->assertOk()->assertJsonPath('data.artist_id', $external->id);
        $this->assertSame(1, ArtistFollow::query()->count());
        $this->deleteJson("/api/v1/artists/{$old->id}/follow")->assertNoContent();
        $this->deleteJson("/api/v1/artists/{$external->id}/follow")->assertNoContent();
        $this->assertFalse(app(ArtistSeedService::class)->state($owner->id, $external->id)['explicit']);

        $album = CatalogEntity::query()->where('kind', 'release_group')->firstOrFail();
        $this->putJson("/api/v1/artists/{$album->id}/follow")->assertNotFound();
    }

    public function test_artist_plex_target_requires_an_active_confirmed_artist_match(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $artist = $this->createHeldArtist('Matched Artist');
        $match = PlexEntityMatch::query()->where('entity_id', $artist->id)->where('match_scope', 'agent')->firstOrFail();
        $artistItem = $match->item()->firstOrFail();
        config()->set('services.plex.expected_machine_identifier', 'machine');
        config()->set('services.plex.expected_library_uuid', 'library');
        $match->update(['status' => 'candidate']);

        $this->actingAs($owner)->getJson("/api/v1/artists/{$artist->id}")
            ->assertOk()
            ->assertJsonPath('data.plex_item_id', null)
            ->assertJsonPath('data.open_in_plex_available', false)
            ->assertJsonPath('data.open_in_plex_status', 'unavailable');

        $match->update(['status' => 'confirmed']);
        $this->getJson("/api/v1/artists/{$artist->id}")
            ->assertOk()
            ->assertJsonPath('data.plex_item_id', $artistItem->id)
            ->assertJsonPath('data.open_in_plex_available', true)
            ->assertJsonPath('data.open_in_plex_status', 'exact');

        $target = $this->getJson("/api/v1/plex/open-target/{$artistItem->id}")
            ->assertOk()
            ->assertJsonPath('status', 'exact');
        $this->assertStringContainsString('%2Flibrary%2Fmetadata%2Fartist', $target->json('url'));

        $match->update(['status' => 'candidate']);
        $this->getJson("/api/v1/plex/open-target/{$artistItem->id}")->assertNotFound();
        $match->update(['status' => 'confirmed']);

        $artistItem->update(['removed_at' => now()]);
        $this->getJson("/api/v1/artists/{$artist->id}")
            ->assertOk()
            ->assertJsonPath('data.plex_item_id', null)
            ->assertJsonPath('data.open_in_plex_available', false)
            ->assertJsonPath('data.open_in_plex_status', 'unavailable');
        $this->getJson("/api/v1/plex/open-target/{$artistItem->id}")->assertNotFound();
    }

    public function test_special_purpose_artists_never_become_preference_seeds(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $artist = $this->createHeldArtist('Various Artists');
        ExternalIdentifier::query()->create([
            'entity_id' => $artist->id,
            'namespace' => 'musicbrainz.artist',
            'value' => '89ad4ac3-39f7-470e-963a-56509c546377',
            'status' => 'active',
        ]);

        $seeds = app(ArtistSeedService::class);
        $this->assertSame(['explicit' => false, 'implicit' => false, 'seed' => false], $seeds->state($owner->id, $artist->id));
        $this->assertArrayNotHasKey('89ad4ac3-39f7-470e-963a-56509c546377', $seeds->exactMbidStates($owner->id));
        $this->actingAs($owner)->putJson("/api/v1/artists/{$artist->id}/follow")->assertUnprocessable();
        $this->assertSame(0, ArtistFollow::query()->count());
    }

    private function createAgent(string $name): CatalogEntity
    {
        $entity = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        Agent::query()->create(['entity_id' => $entity->id, 'agent_type' => 'Person']);

        return $entity;
    }

    private function createHeldArtist(string $name): CatalogEntity
    {
        $artist = $this->createAgent($name);
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Held Album', 'sort_name' => 'Held Album']);
        ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'Album', 'secondary_types' => []]);
        $server = PlexServer::query()->create(['name' => 'Plex', 'machine_identifier' => 'machine', 'machine_identifier_hash' => hash('sha256', 'machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '1', 'section_uuid' => 'library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $artistItem = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => 'artist', 'item_type' => 'artist', 'title' => $name, 'sort_title' => $name, 'raw_metadata' => [], 'last_synced_at' => now()]);
        $albumItem = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => 'album', 'parent_rating_key' => 'artist', 'item_type' => 'album', 'title' => 'Held Album', 'sort_title' => 'Held Album', 'raw_metadata' => [], 'last_synced_at' => now()]);
        PlexEntityMatch::query()->create(['plex_item_id' => $artistItem->id, 'entity_id' => $artist->id, 'match_scope' => 'agent', 'status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1]);
        PlexEntityMatch::query()->create(['plex_item_id' => $albumItem->id, 'entity_id' => $group->id, 'match_scope' => 'release_group', 'status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1]);
        Holding::query()->create(['release_group_id' => $group->id, 'plex_album_item_id' => $albumItem->id, 'ownership_type' => 'digital', 'is_primary_playback_copy' => true]);

        return $artist;
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
