<?php

namespace Tests;

use App\Models\CatalogEntity;
use App\Models\DiscoveryBatch;
use App\Models\DiscoveryPick;
use App\Models\ExternalIdentifier;
use App\Models\ListeningEvent;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\SourceAccount;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

abstract class DiscoveryTestCase extends TestCase
{
    protected User $owner;

    protected SourceAccount $account;

    protected SourceSnapshot $snapshot;

    protected PlexLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Use compose.test.yaml for PostgreSQL tests.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than disco_test.');
        }
        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-16T10:00:00Z'));
        config()->set('services.listenbrainz.username', 'fixture-listener');
        config()->set('services.listenbrainz.token', 'fixture-token');
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.rate_interval_ms', 0);
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        config()->set('services.plex.artwork_auto_ingest', false);
        $this->owner = User::query()->create(['name' => 'Fixture owner', 'email' => 'owner@example.test', 'password' => 'fixture-password']);
        $provider = SourceProvider::query()->create(['slug' => 'listenbrainz', 'display_name' => 'ListenBrainz', 'enabled' => true]);
        $this->account = SourceAccount::query()->create(['provider_id' => $provider->id, 'owner_user_id' => $this->owner->id, 'external_username' => 'fixture-listener', 'credential_env_key' => 'LISTENBRAINZ_TOKEN', 'cursor' => [], 'status' => 'active']);
        $object = SourceObject::query()->create(['provider_id' => $provider->id, 'object_type' => 'user_listens', 'external_id' => 'fixture', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->snapshot = SourceSnapshot::query()->create(['source_object_id' => $object->id, 'payload_hash' => hash('sha256', 'fixture'), 'retrieved_at' => now(), 'http_status' => 200, 'payload' => [], 'parser_version' => 'fixture']);
        $server = PlexServer::query()->create(['machine_identifier' => 'fixture-server', 'name' => 'Fixture', 'machine_identifier_hash' => hash('sha256', 'fixture-server'), 'last_seen_at' => now()]);
        $this->library = PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '7', 'section_uuid' => 'fixture-library', 'title' => 'Music', 'library_type' => 'artist']);
    }

    protected function mbid(int $number): string
    {
        return sprintf('%08d-1111-4111-8111-111111111111', $number);
    }

    protected function listen(string $name, ?string $artistMbid = null, int $days = 0, ?string $album = null, ?string $groupMbid = null): ListeningEvent
    {
        $at = now()->subDays($days);
        $event = ListeningEvent::query()->create([
            'source_account_id' => $this->account->id, 'source_snapshot_id' => $this->snapshot->id,
            'fingerprint' => hash('sha256', Str::uuid()), 'listened_at' => $at, 'listened_at_epoch' => $at->timestamp,
            'supplied_artist' => $name, 'supplied_track' => 'Synthetic song', 'supplied_release' => $album,
            'release_group_mbid' => $groupMbid,
        ]);
        DB::table('activity.listen_identities')->insert(['listening_event_id' => $event->id, 'artist_mbids' => json_encode($artistMbid ? [$artistMbid] : []), 'release_group_mbid' => $groupMbid, 'created_at' => now(), 'updated_at' => now()]);

        return $event;
    }

    protected function artist(string $name, string $mbid): CatalogEntity
    {
        $entity = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => 'musicbrainz.artist', 'value' => $mbid, 'status' => 'active']);

        return $entity;
    }

    protected function plex(string $name, string $key, string $type = 'artist', ?string $parent = null, array $extra = []): PlexItem
    {
        return PlexItem::query()->create(['plex_library_id' => $this->library->id, 'rating_key' => $key, 'title' => $name, 'item_type' => $type, 'parent_rating_key' => $parent, 'last_synced_at' => now(), 'raw_metadata' => [], ...$extra]);
    }

    protected function batch(string $section = 'new_artists', string $period = '2026-11-09'): DiscoveryBatch
    {
        return DiscoveryBatch::query()->create(['section' => $section, 'period' => $period, 'status' => 'completed', 'started_at' => now()->subWeek(), 'completed_at' => now()->subWeek(), 'summary' => ['picks' => 1]]);
    }

    protected function pick(?DiscoveryBatch $batch = null, array $extra = []): DiscoveryPick
    {
        return DiscoveryPick::query()->create([
            'batch_id' => ($batch ?? $this->batch())->id, 'artist_mbid' => $this->mbid(2), 'artist_name' => 'Candidate',
            'release_group_mbid' => $this->mbid(20), 'title' => 'Candidate album', 'release_type' => 'Album', 'release_date' => '2026-11-01',
            'reason' => 'Similar to Seed', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact', 'position' => 0, ...$extra,
        ]);
    }
}
