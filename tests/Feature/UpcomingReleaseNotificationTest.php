<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ArtistFollow;
use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\ReleaseGroup;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\UpcomingNotificationDelivery;
use App\Models\UpcomingReleaseGeneration;
use App\Models\UpcomingReleaseItem;
use App\Models\UpcomingReleaseNotification;
use App\Models\User;
use App\Music\Discovery\UpcomingReleaseNotificationGenerator;
use App\Music\Metadata\PipelineStatusService;
use App\Music\Notifications\UpcomingNotificationDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class UpcomingReleaseNotificationTest extends TestCase
{
    public function test_notifications_are_deduplicated_rescheduled_lifecycle_aware_and_owner_scoped(): void
    {
        $this->preparePostgres();
        CarbonImmutable::setTestNow('2026-07-24 05:00:00');
        Http::preventStrayRequests();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $artist = $this->createArtist('Fixture Artist', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        ArtistFollow::query()->create(['user_id' => $owner->id, 'artist_entity_id' => $artist->id]);
        $group = $this->createReleaseGroup('Future Forms');
        $generator = app(UpcomingReleaseNotificationGenerator::class);

        $first = $this->generation(1, [[$group, '2026-08-14', 'Future Forms']]);
        $result = $generator->generate();
        $this->assertSame($first->id, $result['generation_id']);
        $this->assertSame(1, $result['created']);
        $generator->generate();
        $this->assertDatabaseCount('discovery.upcoming_release_notifications', 1);
        $notification = UpcomingReleaseNotification::query()->sole();

        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->actingAs($owner);
        $this->getJson('/api/v1/notifications?filter=unread&page[size]=10')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.artist', 'Fixture Artist')
            ->assertJsonPath('data.0.title', 'Future Forms')
            ->assertJsonPath('data.0.release_date', '2026-08-14')
            ->assertJsonPath('data.0.personalization.match', 'followed')
            ->assertJsonPath('data.0.source.provider_name', 'ListenBrainz')
            ->assertJsonPath('data.0.links.album', "/albums/{$group->id}")
            ->assertJsonPath('data.0.read', false);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.unread_notification_count', 1);
        $read = $this->patchJson("/api/v1/notifications/{$notification->id}", ['read' => true])
            ->assertOk()->assertJsonPath('data.read', true)->json('data.read_at');
        $this->patchJson("/api/v1/notifications/{$notification->id}", ['read' => true])
            ->assertOk()->assertJsonPath('data.read_at', $read);
        $this->patchJson('/api/v1/notifications/11111111-1111-4111-8111-111111111111', ['read' => true])->assertNotFound();

        $alias = $this->createReleaseGroup('Future Forms alias');
        $duplicate = $notification->replicate();
        $duplicate->release_group_id = $alias->id;
        $duplicate->save();
        $alias->update(['status' => 'redirected', 'redirect_entity_id' => $group->id]);
        $generator->generate();
        $this->assertDatabaseCount('discovery.upcoming_release_notifications', 1);
        $this->assertDatabaseHas('discovery.upcoming_release_notifications', ['id' => $notification->id, 'release_group_id' => $group->id]);

        $this->generation(2, [[$group, '2026-08-20', 'Future Forms']]);
        $generator->generate();
        $notification->refresh();
        $this->assertSame('2026-08-20', $notification->release_date->toDateString());
        $this->assertNull($notification->read_at, 'A date move should reset the existing row to unread.');
        $this->assertDatabaseCount('discovery.upcoming_release_notifications', 1);

        $this->createHolding($group);
        $generator->generate();
        $this->assertDatabaseHas('discovery.upcoming_release_notifications', ['id' => $notification->id, 'status' => 'resolved', 'resolution_reason' => 'owned']);
        $this->assertNotNull($notification->refresh()->read_at);
        $this->patchJson("/api/v1/notifications/{$notification->id}", ['read' => false])->assertUnprocessable();
        $this->getJson('/api/v1/me')->assertJsonPath('data.unread_notification_count', 0);
        Holding::query()->delete();
        $this->generation(3, [[$group, '2026-08-20', 'Future Forms']]);
        $generator->generate();
        $notification->refresh();
        $this->assertSame('active', $notification->status);
        $this->assertNull($notification->read_at, 'A release reappearing after resolution should be unread.');

        $this->patchJson("/api/v1/notifications/{$notification->id}", ['read' => true])->assertOk();
        $this->generation(4, []);
        $generator->generate();
        $notification->refresh();
        $this->assertSame('active', $notification->status);
        $this->assertSame(1, $notification->absence_count);
        $this->assertNotNull($notification->read_at);
        $this->generation(5, []);
        $generator->generate();
        $notification->refresh();
        $this->assertSame('withdrawn', $notification->status);
        $this->assertSame('source_absent', $notification->resolution_reason);
        $this->assertSame(2, $notification->absence_count);
        $this->assertNull($notification->read_at, 'Withdrawal should reset read state.');
        $generator->generate();
        $this->assertSame(2, $notification->refresh()->absence_count, 'The same generation must not count an absence twice.');
        $this->getJson('/api/v1/notifications?filter=active')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications?filter=unread')->assertOk()->assertJsonPath('data.0.status', 'withdrawn');

        $stale = $this->generation(6, [], true);
        try {
            $generator->generate();
            $this->fail('A stale generation should fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
        }
        $this->assertSame(2, $notification->refresh()->absence_count);
        $this->assertSame($stale->id, UpcomingReleaseGeneration::query()->latest('generated_at')->value('id'));

        $this->generation(7, [[$group, '2026-08-20', 'Future Forms']]);
        $generator->generate();
        $this->assertSame('active', $notification->refresh()->status);
        $this->assertSame(0, $notification->absence_count);
        ArtistFollow::query()->delete();
        $generator->generate();
        $this->assertDatabaseHas('discovery.upcoming_release_notifications', ['id' => $notification->id, 'status' => 'resolved', 'resolution_reason' => 'no_longer_personalized']);
        ArtistFollow::query()->create(['user_id' => $owner->id, 'artist_entity_id' => $artist->id]);
        $generator->generate();
        $this->assertSame('active', $notification->refresh()->status);

        $this->generation(8, [[$group, '2026-07-23', 'Future Forms']]);
        $generator->generate();
        $this->assertDatabaseHas('discovery.upcoming_release_notifications', ['id' => $notification->id, 'status' => 'resolved', 'resolution_reason' => 'released']);

        $past = $this->createReleaseGroup('Already Released');
        $this->generation(9, [[$past, '2026-07-22', 'Already Released']]);
        $this->assertSame(0, $generator->generate()['created']);
        $this->assertDatabaseMissing('discovery.upcoming_release_notifications', ['release_group_id' => $past->id]);

        $other = $this->createReleaseGroup('Another Record');
        $this->generation(10, [[$group, '2026-08-20', 'Future Forms'], [$other, '2026-08-21', 'Another Record']]);
        try {
            $generator->generate(1);
            $this->fail('An oversized generation should fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('safety limit', $exception->getMessage());
        }
        $this->assertDatabaseCount('discovery.upcoming_release_notifications', 1);
    }

    public function test_notification_generation_is_scheduled_after_the_upcoming_refresh(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:upcoming-notifications'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        $delivery = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:upcoming-notification-delivery'));
        $this->assertInstanceOf(Event::class, $delivery);
        $this->assertSame('5,20,35,50 * * * *', $delivery->expression);
        $this->assertTrue($delivery->withoutOverlapping);
        $this->assertTrue($delivery->onOneServer);
    }

    public function test_gotify_delivery_is_deduplicated_retryable_and_isolated_from_generation(): void
    {
        $this->preparePostgres();
        CarbonImmutable::setTestNow('2026-07-24 05:00:00');
        config()->set('app.url', 'https://disco.example.test');
        config()->set('services.gotify', [
            'url' => 'https://notify.example.test',
            'token' => 'private-application-token',
            'timeout' => 10,
            'priority' => 5,
        ]);
        config()->set('services.listenbrainz.enabled', true);
        config()->set('services.listenbrainz.username', 'fixture-user');
        config()->set('services.listenbrainz.token', 'fixture-token');
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $artist = $this->createArtist('Fixture Artist', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        ArtistFollow::query()->create(['user_id' => $owner->id, 'artist_entity_id' => $artist->id]);
        $retry = $this->createReleaseGroup('Retry Record');
        $resolved = $this->createReleaseGroup('Resolved Record');
        $withdrawn = $this->createReleaseGroup('Withdrawn Record');
        $owned = $this->createReleaseGroup('Already Owned');
        $this->createHolding($owned);
        $this->generation(1, [
            [$retry, '2026-08-14', 'Retry Record'],
            [$resolved, '2026-08-15', 'Resolved Record'],
            [$withdrawn, '2026-08-16', 'Withdrawn Record'],
            [$owned, '2026-08-17', 'Already Owned'],
        ]);

        $generator = app(UpcomingReleaseNotificationGenerator::class);
        $generated = $generator->generate();
        $this->assertSame(3, $generated['created']);
        $this->assertDatabaseCount('discovery.upcoming_release_notifications', 3);
        $this->assertDatabaseCount('discovery.upcoming_notification_deliveries', 3);
        UpcomingReleaseNotification::query()->where('release_group_id', $resolved->id)->update(['status' => 'resolved', 'resolution_reason' => 'owned']);
        UpcomingReleaseNotification::query()->where('release_group_id', $withdrawn->id)->update(['status' => 'withdrawn', 'resolution_reason' => 'source_absent']);
        Http::fakeSequence()
            ->push(['error' => 'unavailable'], 503)
            ->push(['id' => 42], 200);

        $first = app(UpcomingNotificationDeliveryService::class)->deliver();
        $this->assertSame(['requested' => 3, 'delivered' => 0, 'failed' => 1, 'skipped' => 2], $first);
        $this->assertDatabaseHas('discovery.upcoming_notification_deliveries', [
            'notification_id' => UpcomingReleaseNotification::query()->where('release_group_id', $retry->id)->value('id'),
            'status' => 'failed',
            'attempt_count' => 1,
            'error_code' => 'http_503',
        ]);
        $pipeline = collect(app(PipelineStatusService::class)->summarize())->firstWhere('key', 'upcoming');
        $this->assertSame('attention', $pipeline['status']);
        $this->assertSame(1, collect($pipeline['metrics'])->firstWhere('label', 'Alerts failed')['value']);
        $this->assertSame(0, $generator->generate()['created'], 'Provider failure must not affect or duplicate feed generation.');
        $this->assertDatabaseCount('discovery.upcoming_notification_deliveries', 3);

        CarbonImmutable::setTestNow('2026-07-24 05:16:00');
        $second = app(UpcomingNotificationDeliveryService::class)->deliver();
        $this->assertSame(['requested' => 1, 'delivered' => 1, 'failed' => 0, 'skipped' => 0], $second);
        $this->assertSame(['requested' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0], app(UpcomingNotificationDeliveryService::class)->deliver());
        $this->assertSame(2, Http::recorded()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://notify.example.test/message'
            && $request->hasHeader('X-Gotify-Key', 'private-application-token')
            && ! str_contains($request->url(), 'private-application-token')
            && $request['title'] === 'New release from Fixture Artist'
            && str_contains($request['message'], 'Retry Record arrives 14 Aug 2026.')
            && data_get($request->data(), 'extras.client::notification.click.url') === "https://disco.example.test/albums/{$retry->id}");
        $delivery = UpcomingNotificationDelivery::query()->where('status', 'delivered')->sole();
        $this->assertSame('42', $delivery->external_id);
        $this->assertSame(2, $delivery->attempt_count);
    }

    /** @param list<array{0:CatalogEntity,1:string,2:string}> $items */
    private function generation(int $sequence, array $items, bool $stale = false): UpcomingReleaseGeneration
    {
        $provider = SourceProvider::query()->firstOrCreate(['slug' => 'listenbrainz'], ['display_name' => 'ListenBrainz', 'enabled' => true, 'policy' => []]);
        $object = SourceObject::query()->firstOrCreate(['provider_id' => $provider->id, 'object_type' => 'fresh_releases', 'external_id' => 'sitewide'], [
            'canonical_url' => 'https://listenbrainz.example/releases', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $snapshot = SourceSnapshot::query()->create([
            'source_object_id' => $object->id, 'retrieved_at' => now()->addMinutes($sequence), 'http_status' => 200,
            'payload_hash' => hash('sha256', "generation-{$sequence}"), 'payload' => ['sequence' => $sequence], 'parser_version' => 'fixture-v1', 'expires_at' => now()->addDay(),
        ]);
        $generation = UpcomingReleaseGeneration::query()->create([
            'source_snapshot_id' => $snapshot->id, 'algorithm_version' => 'fixture-v1', 'horizon_days' => 60,
            'horizon_reason' => 'Fixture horizon.', 'coverage' => ['pivot_date' => '2026-07-24'],
            'generated_at' => now()->addMinutes($sequence), 'expires_at' => $stale ? now()->subMinute() : now()->addDay(),
        ]);
        foreach ($items as $rank => [$group, $date, $title]) {
            UpcomingReleaseItem::query()->create([
                'generation_id' => $generation->id, 'release_group_id' => $group->id,
                'release_group_mbid' => $this->mbid($rank + ($sequence * 10), '10000000'),
                'release_mbid' => $this->mbid($rank + ($sequence * 10), '20000000'),
                'title' => $title, 'artist_credit_name' => 'Fixture Artist',
                'artist_mbids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], 'release_date' => $date,
                'primary_type' => 'Album', 'secondary_types' => [], 'artwork_status' => 'unavailable',
                'listen_count' => 1, 'tags' => [], 'general_rank' => $rank + 1,
                'provenance' => ['provider' => 'listenbrainz', 'provider_name' => 'ListenBrainz', 'source_url' => 'https://listenbrainz.example/releases'],
            ]);
        }

        return $generation;
    }

    private function createArtist(string $name, string $mbid): CatalogEntity
    {
        $artist = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        Agent::query()->create(['entity_id' => $artist->id, 'agent_type' => 'Group']);
        ExternalIdentifier::query()->create(['entity_id' => $artist->id, 'namespace' => 'musicbrainz.artist', 'value' => $mbid, 'status' => 'active']);

        return $artist;
    }

    private function createReleaseGroup(string $title): CatalogEntity
    {
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => $title, 'sort_name' => $title]);
        ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'album', 'secondary_types' => []]);

        return $group;
    }

    private function createHolding(CatalogEntity $group): void
    {
        $server = PlexServer::query()->create(['name' => 'Plex', 'machine_identifier' => 'machine', 'machine_identifier_hash' => hash('sha256', 'machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '1', 'section_uuid' => 'library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $album = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => 'album', 'item_type' => 'album', 'title' => $group->canonical_name, 'sort_title' => $group->sort_name, 'raw_metadata' => [], 'last_synced_at' => now()]);
        Holding::query()->create(['release_group_id' => $group->id, 'plex_album_item_id' => $album->id, 'ownership_type' => 'digital', 'is_primary_playback_copy' => true]);
    }

    private function mbid(int $value, string $prefix): string
    {
        return sprintf('%s-0000-4000-8000-%012d', $prefix, $value);
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
