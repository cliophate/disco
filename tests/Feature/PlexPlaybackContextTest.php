<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Plex\PlexPlaybackContextService;
use App\Music\Plex\PlexPlaybackContextSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PlexPlaybackContextTest extends TestCase
{
    public function test_context_is_exact_short_lived_and_provider_free_at_read_time(): void
    {
        $this->preparePostgres();
        config()->set('services.plex.url', 'https://plex.test');
        config()->set('services.plex.token', 'fixture-token');
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        config()->set('services.plex.expected_library_uuid', 'fixture-library');
        config()->set('services.plex.library', 'Music');
        config()->set('services.plex.playback_context_ttl_seconds', 120);
        config()->set('services.plex.playback_recent_days', 90);
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $album = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Fixture Album', 'sort_name' => 'Fixture Album']);
        ReleaseGroup::query()->create(['entity_id' => $album->id, 'primary_type' => 'Album', 'secondary_types' => []]);
        $server = PlexServer::query()->create(['name' => 'Plex', 'machine_identifier' => 'test-machine', 'machine_identifier_hash' => hash('sha256', 'test-machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '7', 'section_uuid' => 'fixture-library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $albumItem = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => '201', 'item_type' => 'album', 'title' => 'Fixture Album', 'sort_title' => 'Fixture Album', 'raw_metadata' => [], 'last_synced_at' => now()]);
        PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => '301', 'parent_rating_key' => '201', 'item_type' => 'track', 'title' => 'Fixture Track', 'sort_title' => 'Fixture Track', 'raw_metadata' => [], 'last_synced_at' => now()]);
        PlexEntityMatch::query()->create(['plex_item_id' => $albumItem->id, 'entity_id' => $album->id, 'match_scope' => 'release_group', 'status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1]);
        Holding::query()->create(['release_group_id' => $album->id, 'plex_album_item_id' => $albumItem->id, 'ownership_type' => 'digital', 'is_primary_playback_copy' => true]);

        $context = app(PlexPlaybackContextService::class);
        $this->assertSame('available', $context->forReleaseGroup($album->id)['status']);
        $albumItem->update(['last_viewed_at' => now()->subDay()]);
        $this->assertSame('recently_played', $context->forReleaseGroup($album->id)['status']);

        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="test-machine" friendlyName="Test" />', 200, ['Content-Type' => 'application/xml']),
            'https://plex.test/library/sections' => Http::response('<MediaContainer><Directory key="7" uuid="fixture-library" title="Music" type="artist" /></MediaContainer>', 200, ['Content-Type' => 'application/xml']),
            'https://plex.test/status/sessions' => Http::sequence()
                ->push('<MediaContainer><Track ratingKey="301" parentRatingKey="201"><User title="private"/><Player address="private" state="paused"/><Session id="private"/></Track></MediaContainer>', 200, ['Content-Type' => 'application/xml'])
                ->push('<MediaContainer size="0" />', 200, ['Content-Type' => 'application/xml']),
        ]);
        $result = app(PlexPlaybackContextSyncService::class)->sync();
        $this->assertSame(['observed' => 1, 'matched' => 1], $result);
        $active = $context->forReleaseGroup($album->id);
        $this->assertSame('currently_active', $active['status']);
        $this->assertSame('paused', $active['player_state']);
        $this->assertArrayNotHasKey('user', Cache::get(PlexPlaybackContextService::cacheKey()));

        config()->set('services.plex.expected_machine_identifier', 'expected-other-machine');
        $sentBeforeMismatch = Http::recorded()->count();
        try {
            app(PlexPlaybackContextSyncService::class)->sync();
            $this->fail('A machine mismatch must abort playback polling.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('machine identifier mismatch', $exception->getMessage());
        }
        $this->assertCount($sentBeforeMismatch + 1, Http::recorded());
        $this->assertFalse(Http::recorded()->last()[0]->hasHeader('X-Plex-Token'));
        config()->set('services.plex.expected_machine_identifier', 'test-machine');

        app(PlexPlaybackContextSyncService::class)->sync();
        $this->assertSame('recently_played', $context->forReleaseGroup($album->id)['status']);

        $sentBeforeRead = Http::recorded()->count();
        $response = $this->actingAs($owner)->getJson("/api/v1/albums/{$album->id}")->assertOk()
            ->assertJsonPath('data.plex_playback_context.status', 'recently_played');
        $this->assertSame(['status', 'basis', 'player_state', 'observed_at', 'last_played_at', 'expires_at', 'availability_as_of'], array_keys($response->json('data.plex_playback_context')));
        $this->assertCount($sentBeforeRead, Http::recorded());

        Cache::put(PlexPlaybackContextService::cacheKey(), ['observed_at' => now()->subMinutes(3)->toAtomString(), 'expires_at' => now()->subMinute()->toAtomString(), 'albums' => [$album->id => ['state' => 'playing']]], 120);
        $albumItem->update(['last_viewed_at' => now()->subDays(91)]);
        $this->assertSame('available', $context->forReleaseGroup($album->id)['status']);
        $albumItem->update(['removed_at' => now()]);
        $this->assertSame('unavailable', $context->forReleaseGroup($album->id)['status']);
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
