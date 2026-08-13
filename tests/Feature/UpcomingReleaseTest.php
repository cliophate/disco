<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ArtistFollow;
use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\UpcomingReleaseGeneration;
use App\Models\User;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Discovery\CatalogEnrichmentService;
use App\Music\Discovery\UpcomingReleaseRefresher;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class UpcomingReleaseTest extends TestCase
{
    public function test_upcoming_cache_is_exact_filtered_personalized_pinned_and_provider_free(): void
    {
        $this->preparePostgres();
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.user_agent', 'Disco tests');
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $followed = $this->createArtist('Followed Artist', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        ArtistFollow::query()->create(['user_id' => $user->id, 'artist_entity_id' => $followed->id]);
        $this->createHeldArtist('Held Artist', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $this->createHeldArtist('Various Artists', '89ad4ac3-39f7-470e-963a-56509c546377');
        $this->actingAs($user);

        $this->getJson('/api/v1/discover/upcoming')
            ->assertOk()
            ->assertJsonPath('meta.status', 'empty')
            ->assertJsonCount(0, 'data');

        $releases = [
            $this->release(1, 10, 'Album', null, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Followed Artist'),
            $this->release(2, -10, 'EP', null, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Held Artist'),
            $this->release(3, 20, 'Album'),
            $this->release(4, -5, 'EP'),
            $this->release(5, 15, 'Album', null, '89ad4ac3-39f7-470e-963a-56509c546377', 'Various Artists'),
            $this->release(90, 12, 'Single'),
            $this->release(91, 13, 'Album', 'Compilation'),
            $this->release(92, -30, 'Album'),
            $this->release(93, 30, 'Album'),
        ];
        $this->fakeFreshReleases($releases);

        $refresh = app(UpcomingReleaseRefresher::class)->refresh(CarbonImmutable::parse('2026-07-24'));

        $this->assertSame(30, $refresh['horizon_days']);
        $this->assertSame(7, $refresh['items']);
        $this->assertDatabaseCount('discovery.upcoming_release_items', 7);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['days'] === '30' && $query['past'] === 'true' && $query['future'] === 'false';
        });
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['days'] === '30' && $query['past'] === 'false' && $query['future'] === 'true';
        });
        $providerCalls = count(Http::recorded());

        $personal = $this->getJson('/api/v1/discover/upcoming?view=for-you&page[size]=1')
            ->assertOk()
            ->assertJsonPath('meta.generation_id', $refresh['generation_id'])
            ->assertJsonPath('meta.horizon_days', 30)
            ->assertJsonPath('meta.window_start', '2026-06-24')
            ->assertJsonPath('meta.window_end', '2026-08-23')
            ->assertJsonPath('meta.past_days', 30)
            ->assertJsonPath('meta.future_days', 30)
            ->assertJsonPath('meta.coverage.source_past_total', 3)
            ->assertJsonPath('meta.coverage.source_future_total', 6)
            ->assertJsonPath('meta.coverage.source_total', 9)
            ->assertJsonPath('meta.coverage.eligible_groups', 7)
            ->assertJsonPath('meta.coverage.eligible_past', 3)
            ->assertJsonPath('meta.coverage.eligible_future', 4)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.personalization.match', 'followed')
            ->assertJsonPath('data.0.musicbrainz.release_group_mbid', $this->mbid(1, '10000000'))
            ->assertJsonPath('data.0.provenance.identity_method', 'exact_musicbrainz_ids');
        $this->assertStringContainsString('generation_id='.$refresh['generation_id'], $personal->json('links.next'));
        $this->getJson('/api/v1/discover/upcoming?view=for-you&page[number]=2&page[size]=1&generation_id='.$refresh['generation_id'])
            ->assertOk()
            ->assertJsonPath('data.0.personalization.match', 'library');
        $this->getJson('/api/v1/discover/upcoming?view=all&page[size]=48')
            ->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.album.identity_status', 'confirmed')
            ->assertJsonPath('data.0.album.qobuz_search_url', 'https://www.qobuz.com/us-en/search/?q=Followed%20Artist%20Release%201');
        $this->assertCount($providerCalls, Http::recorded(), 'Cached reads must not contact ListenBrainz or another provider.');

        Storage::fake('artwork');
        $firstGroup = CatalogEntity::query()->whereHas('identifiers', fn ($query) => $query
            ->where('namespace', 'musicbrainz.release_group')->where('value', $this->mbid(1, '10000000')))->firstOrFail();
        $this->mock(MusicBrainzEnricher::class, function (MockInterface $mock) use ($firstGroup): void {
            $mock->shouldReceive('enrich')->once()->andReturn($firstGroup->metadata);
        });
        $this->mock(CoverArtArchiveIngestor::class, function (MockInterface $mock) use ($firstGroup): void {
            $mock->shouldReceive('ingest')->once()->andReturnUsing(function () use ($firstGroup): CatalogEntityArtwork {
                $body = 'catalog-artwork';
                $checksum = hash('sha256', $body);
                $storageKey = "cover-art-archive/{$checksum}.webp";
                Storage::disk('artwork')->put($storageKey, $body);

                return CatalogEntityArtwork::query()->create([
                    'entity_id' => $firstGroup->id,
                    'status' => 'ready',
                    'source_release_mbid' => $this->mbid(1, '20000000'),
                    'source_image_id' => '1',
                    'source_hash' => hash('sha256', 'source'),
                    'content_sha256' => $checksum,
                    'storage_key' => $storageKey,
                    'mime_type' => 'image/webp',
                    'size_bytes' => strlen($body),
                    'width' => 1,
                    'height' => 1,
                    'attempt_count' => 1,
                    'last_attempt_at' => now(),
                    'ingested_at' => now(),
                ]);
            });
        });
        $enrichment = app(CatalogEnrichmentService::class)->enrich(1);
        $this->assertSame(1, $enrichment['requested']);
        $this->assertSame(1, $enrichment['detail']);
        $this->assertSame(1, $enrichment['artwork']);
        $this->getJson('/api/v1/discover/upcoming?view=all&page[size]=48')
            ->assertOk()->assertJsonPath('data.0.album.artwork.width', 1);

        $this->fakeFreshReleases([$this->release(20, -2, 'Album'), $this->release(21, 2, 'EP')]);
        $latest = app(UpcomingReleaseRefresher::class)->refresh(CarbonImmutable::parse('2026-07-24'));
        $this->assertSame(30, $latest['horizon_days']);
        $this->assertSame(2, $latest['items']);
        $this->getJson('/api/v1/discover/upcoming?view=all&page[size]=48')
            ->assertOk()->assertJsonPath('meta.generation_id', $latest['generation_id'])->assertJsonPath('meta.total', 2);
        UpcomingReleaseGeneration::query()->findOrFail($refresh['generation_id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/discover/upcoming?view=all&page[size]=48&generation_id='.$refresh['generation_id'])
            ->assertOk()->assertJsonPath('meta.stale', true)->assertJsonPath('meta.status', 'stale')->assertJsonPath('meta.total', 7);
    }

    public function test_upcoming_refresh_is_daily_bounded_and_single_server(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:upcoming-releases'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_upcoming_refresh_skips_conflicting_release_identity_without_discarding_generation(): void
    {
        $this->preparePostgres();
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.user_agent', 'Disco tests');

        $existingGroup = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => 'Previous Group',
            'sort_name' => 'Previous Group',
        ]);
        ReleaseGroup::query()->create(['entity_id' => $existingGroup->id, 'primary_type' => 'album', 'secondary_types' => []]);
        ExternalIdentifier::query()->create([
            'entity_id' => $existingGroup->id,
            'namespace' => 'musicbrainz.release_group',
            'value' => $this->mbid(99, '10000000'),
            'status' => 'active',
        ]);
        $existingRelease = CatalogEntity::query()->create([
            'kind' => 'release',
            'status' => 'active',
            'canonical_name' => 'Moved Release',
            'sort_name' => 'Moved Release',
        ]);
        Release::query()->create(['entity_id' => $existingRelease->id, 'release_group_id' => $existingGroup->id]);
        ExternalIdentifier::query()->create([
            'entity_id' => $existingRelease->id,
            'namespace' => 'musicbrainz.release',
            'value' => $this->mbid(1, '20000000'),
            'status' => 'active',
        ]);
        $this->fakeFreshReleases([
            $this->release(1, 1, 'Album'),
            $this->release(2, 2, 'Album'),
        ]);

        $refresh = app(UpcomingReleaseRefresher::class)->refresh(CarbonImmutable::parse('2026-07-24'));

        $this->assertSame(1, $refresh['items']);
        $this->assertSame(2, $refresh['coverage']['eligible_groups']);
        $this->assertSame(1, $refresh['coverage']['materialized_groups']);
        $this->assertSame(1, $refresh['coverage']['materialization_skipped']);
        $this->assertDatabaseMissing('discovery.upcoming_release_items', [
            'release_mbid' => $this->mbid(1, '20000000'),
        ]);
        $this->assertDatabaseHas('discovery.upcoming_release_items', [
            'release_mbid' => $this->mbid(2, '20000000'),
        ]);
    }

    /** @param list<array<string,mixed>> $releases */
    private function fakeFreshReleases(array $releases): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake(function (Request $request) use ($releases) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $pivot = '2026-07-24';
            $selected = collect($releases)->filter(fn (array $release): bool => ($query['past'] ?? 'false') === 'true'
                ? $release['release_date'] <= $pivot
                : $release['release_date'] >= $pivot)->values()->all();

            return Http::response(['payload' => ['total_count' => count($selected), 'releases' => $selected]], 200, ['Content-Type' => 'application/json']);
        });
    }

    /** @return array<string,mixed> */
    private function release(int $group, int $days, string $type, ?string $secondary = null, ?string $artistMbid = null, ?string $artistName = null, ?int $release = null): array
    {
        $release ??= $group;

        return array_filter([
            'artist_credit_name' => $artistName ?? "Artist {$group}",
            'artist_mbids' => [$artistMbid ?? $this->mbid($group, '30000000')],
            'release_date' => CarbonImmutable::parse('2026-07-24')->addDays($days)->toDateString(),
            'release_group_mbid' => $this->mbid($group, '10000000'),
            'release_group_primary_type' => $type,
            'release_group_secondary_type' => $secondary,
            'release_mbid' => $this->mbid($release, '20000000'),
            'release_name' => "Release {$group}",
            'caa_id' => $group === 1 ? 123456 : null,
            'caa_release_mbid' => $group === 1 ? $this->mbid($release, '20000000') : null,
            'listen_count' => $group,
            'release_tags' => ['fixture'],
        ], fn ($value): bool => $value !== null);
    }

    private function mbid(int $value, string $prefix): string
    {
        return sprintf('%s-0000-4000-8000-%012d', $prefix, $value);
    }

    private function createArtist(string $name, string $mbid): CatalogEntity
    {
        $artist = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        Agent::query()->create(['entity_id' => $artist->id, 'agent_type' => 'Group']);
        ExternalIdentifier::query()->create(['entity_id' => $artist->id, 'namespace' => 'musicbrainz.artist', 'value' => $mbid, 'status' => 'active']);

        return $artist;
    }

    private function createHeldArtist(string $name, string $mbid): void
    {
        $artist = $this->createArtist($name, $mbid);
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Held Album', 'sort_name' => 'Held Album']);
        ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'album', 'secondary_types' => []]);
        $server = PlexServer::query()->firstOrCreate(['machine_identifier' => 'machine'], ['name' => 'Plex', 'machine_identifier_hash' => hash('sha256', 'machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->firstOrCreate(['plex_server_id' => $server->id, 'section_key' => '1'], ['section_uuid' => 'library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $key = substr(str_replace('-', '', $mbid), -12);
        $artistItem = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => "artist-{$key}", 'item_type' => 'artist', 'title' => $name, 'sort_title' => $name, 'raw_metadata' => [], 'last_synced_at' => now()]);
        $albumItem = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => "album-{$key}", 'parent_rating_key' => $artistItem->rating_key, 'item_type' => 'album', 'title' => 'Held Album', 'sort_title' => 'Held Album', 'raw_metadata' => [], 'last_synced_at' => now()]);
        PlexEntityMatch::query()->create(['plex_item_id' => $artistItem->id, 'entity_id' => $artist->id, 'match_scope' => 'agent', 'status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1]);
        PlexEntityMatch::query()->create(['plex_item_id' => $albumItem->id, 'entity_id' => $group->id, 'match_scope' => 'release_group', 'status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1]);
        Holding::query()->create(['release_group_id' => $group->id, 'plex_album_item_id' => $albumItem->id, 'ownership_type' => 'digital', 'is_primary_playback_copy' => true]);
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
