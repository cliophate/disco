<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\ListenImportRun;
use App\Models\ListeningEvent;
use App\Models\ListeningEventMatch;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\Recording;
use App\Models\SourceAccount;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Music\Activity\TrackListeningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class TrackListeningTest extends TestCase
{
    public function test_projection_keeps_provider_counts_separate_and_distinguishes_zero_unknown_and_identity_states(): void
    {
        $this->preparePostgres();
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $provider = SourceProvider::query()->create(['slug' => 'listenbrainz', 'display_name' => 'ListenBrainz', 'enabled' => true, 'policy' => []]);
        $account = SourceAccount::query()->create(['provider_id' => $provider->id, 'owner_user_id' => $user->id, 'external_username' => 'fixture', 'credential_env_key' => 'LISTENBRAINZ_TOKEN', 'cursor' => [], 'status' => 'active', 'last_success_at' => now()]);
        $run = ListenImportRun::query()->create(['source_account_id' => $account->id, 'mode' => 'full', 'status' => 'completed', 'start_cursor' => [], 'end_cursor' => [], 'counts' => [], 'errors' => [], 'started_at' => now()->subMinute(), 'completed_at' => now()]);
        $object = SourceObject::query()->create(['provider_id' => $provider->id, 'object_type' => 'listens', 'external_id' => 'fixture', 'canonical_url' => null, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $snapshot = SourceSnapshot::query()->create(['source_object_id' => $object->id, 'retrieved_at' => now(), 'http_status' => 200, 'payload_hash' => hash('sha256', 'fixture'), 'payload' => [], 'parser_version' => 'fixture', 'expires_at' => null]);
        $counted = $this->recording('Counted Recording');
        $redirected = $this->recording('Redirected Recording');
        $redirected->update(['status' => 'redirected', 'redirect_entity_id' => $counted->id]);
        $zero = $this->recording('Zero Recording');
        $unknown = $this->recording('Unknown Recording');
        $library = $this->library();
        $this->plexTrack($library, 'counted-a', $counted, 3, now()->subDays(2));
        $this->plexTrack($library, 'counted-b', $counted, 5, now()->subDay());
        $this->plexTrack($library, 'zero', $zero, 0, null);
        $this->plexTrack($library, 'unknown', $unknown, null, now()->subDays(3));
        foreach ([now()->subDays(4), now()->subDay()] as $index => $listenedAt) {
            $event = ListeningEvent::query()->create([
                'source_account_id' => $account->id, 'source_snapshot_id' => $snapshot->id,
                'fingerprint' => hash('sha256', "listen-{$index}"), 'listened_at' => $listenedAt,
                'listened_at_epoch' => $listenedAt->timestamp, 'supplied_artist' => 'Fixture',
                'supplied_release' => 'Fixture', 'supplied_track' => 'Counted Recording',
                'recording_mbid' => null, 'identifier_conflicts' => [], 'raw_additional_info' => [],
            ]);
            ListeningEventMatch::query()->create([
                'listening_event_id' => $event->id, 'recording_entity_id' => $counted->id,
                'release_group_entity_id' => null, 'plex_track_item_id' => null, 'status' => 'matched',
                'method' => 'musicbrainz_exact', 'confidence' => 1, 'evidence' => [],
                'source_present' => true, 'last_seen_import_run_id' => $run->id,
            ]);
        }

        $result = app(TrackListeningService::class)->attach(collect([
            ['id' => 'counted', '_recording_id' => $counted->id],
            ['id' => 'redirected', '_recording_id' => $redirected->id],
            ['id' => 'zero', '_recording_id' => $zero->id],
            ['id' => 'unknown', '_recording_id' => $unknown->id],
            ['id' => 'unmatched', '_recording_id' => null],
        ]), $user->id)->keyBy('id');

        $this->assertSame('counted', data_get($result, 'counted.listening.plex.status'));
        $this->assertSame(5, data_get($result, 'counted.listening.plex.play_count'));
        $this->assertSame(2, data_get($result, 'counted.listening.plex.copy_count'));
        $this->assertSame('maximum_across_exact_copies', data_get($result, 'counted.listening.plex.aggregation'));
        $this->assertSame(2, data_get($result, 'counted.listening.listenbrainz.play_count'));
        $this->assertSame(5, data_get($result, 'redirected.listening.plex.play_count'));
        $this->assertSame(2, data_get($result, 'redirected.listening.listenbrainz.play_count'));
        $this->assertSame('known_zero', data_get($result, 'zero.listening.plex.status'));
        $this->assertSame('known_zero', data_get($result, 'zero.listening.listenbrainz.status'));
        $this->assertSame('unavailable', data_get($result, 'unknown.listening.plex.status'));
        $this->assertNull(data_get($result, 'unknown.listening.plex.play_count'));
        $this->assertSame('unmatched', data_get($result, 'unmatched.listening.identity_status'));
        $this->assertSame('unmatched_identity', data_get($result, 'unmatched.listening.plex.status'));
        $this->assertArrayNotHasKey('_recording_id', $result['counted']);
        $this->artisan('disco:track-listening-coverage')
            ->expectsOutputToContain('maximum copy count')
            ->assertExitCode(0);
    }

    private function recording(string $name): CatalogEntity
    {
        $entity = CatalogEntity::query()->create(['kind' => 'recording', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        Recording::query()->create(['entity_id' => $entity->id, 'duration_ms' => 180000]);

        return $entity;
    }

    private function library(): PlexLibrary
    {
        $server = PlexServer::query()->create(['name' => 'Fixture', 'machine_identifier' => 'fixture-machine', 'machine_identifier_hash' => hash('sha256', 'fixture-machine'), 'version' => '1', 'last_seen_at' => now()]);

        return PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '1', 'section_uuid' => 'fixture-library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
    }

    private function plexTrack(PlexLibrary $library, string $key, CatalogEntity $recording, ?int $count, mixed $lastViewed): void
    {
        $track = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => $key, 'item_type' => 'track', 'title' => $recording->canonical_name, 'sort_title' => $recording->sort_name, 'view_count' => $count, 'last_viewed_at' => $lastViewed, 'raw_metadata' => [], 'last_synced_at' => now()]);
        PlexEntityMatch::query()->create(['plex_item_id' => $track->id, 'entity_id' => $recording->id, 'match_scope' => 'recording', 'status' => 'confirmed', 'method' => 'musicbrainz_guid', 'confidence' => 1]);
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
