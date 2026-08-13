<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\Holding;
use App\Models\HomeEdition;
use App\Models\ListenImportRun;
use App\Models\PlayAggregate;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\PlexSyncRun;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\SourceAccount;
use App\Models\SourceProvider;
use App\Models\User;
use App\Music\Activity\RecentCollectionActivityService;
use App\Music\Discovery\HomeDiscoveryService;
use App\Music\Discovery\HomeProjectionVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class HomeActivityTest extends TestCase
{
    public function test_home_attaches_bounded_chronological_canonical_activity(): void
    {
        $this->preparePostgres();
        CarbonImmutable::setTestNow('2026-07-24 12:00:00+00:00');

        try {
            $user = $this->createUser('owner@example.test');
            $library = $this->createLibrary();
            $artist = CatalogEntity::query()->create([
                'kind' => 'agent',
                'status' => 'active',
                'canonical_name' => 'Exact Artist',
                'sort_name' => 'Exact Artist',
            ]);
            Agent::query()->create(['entity_id' => $artist->id, 'agent_type' => 'group']);
            $plexArtist = PlexItem::query()->create([
                'plex_library_id' => $library->id,
                'rating_key' => 'exact-artist',
                'item_type' => 'artist',
                'title' => 'Exact Artist',
                'raw_metadata' => [],
                'last_synced_at' => now(),
            ]);
            $this->match($plexArtist, $artist, 'agent');

            [$main, $mainAlbum] = $this->createAlbum($library, 'Main Album', now()->subMinutes(30), 'exact-artist');
            $this->createCopy($library, $main, 'Main Album Earlier Copy', 'main-earlier', now()->subMinutes(90), 'exact-artist');
            $this->createCopy($library, $main, 'Removed Main Copy', 'main-removed', now()->subYear(), 'exact-artist', now());
            PlayAggregate::query()->create([
                'release_group_entity_id' => $main->id,
                'play_count' => 4,
                'first_listened_at' => now()->subDay(),
                'last_listened_at' => now()->subMinutes(15),
            ]);

            $otherIds = [];
            foreach (range(2, 10) as $hours) {
                [$entity] = $this->createAlbum($library, "Album {$hours}", now()->subHours($hours));
                $otherIds[] = $entity->id;
            }
            [$inactive] = $this->createAlbum($library, 'Inactive Album', now()->subMinute());
            $inactive->update(['status' => 'redirected']);
            [$candidate] = $this->createAlbum($library, 'Candidate Album', now()->subMinutes(2), null, 'candidate');

            $this->createPlexSync($library, now()->subMinutes(5));
            [, $ownerImport] = $this->createListenBrainzAccount($user, true, now()->subMinutes(10));
            $this->createEdition($user);

            $response = $this->actingAs($user)->getJson('/api/v1/home')
                ->assertOk()
                ->assertJsonCount(10, 'data.activity')
                ->assertJsonPath('data.activity.0.id', "played:{$main->id}")
                ->assertJsonPath('data.activity.0.kind', 'played')
                ->assertJsonPath('data.activity.0.album.id', $main->id)
                ->assertJsonPath('data.activity.0.album.plex_item_id', $mainAlbum->id)
                ->assertJsonPath('data.activity.0.album.artist.id', $artist->id)
                ->assertJsonPath('meta.activity.status', 'ready')
                ->assertJsonPath('meta.activity.stale', false)
                ->assertJsonPath('meta.last_listenbrainz_import_at', $ownerImport->completed_at->toAtomString());

            $events = collect($response->json('data.activity'));
            $this->assertSame($events->pluck('occurred_at')->sortDesc()->values()->all(), $events->pluck('occurred_at')->all());
            $this->assertSame(1, $events->where('id', "added:{$main->id}")->count());
            $this->assertSame(now()->subMinutes(90)->toAtomString(), $events->firstWhere('id', "added:{$main->id}")['occurred_at']);
            $this->assertTrue($events->contains('id', "played:{$main->id}"));
            $this->assertFalse($events->pluck('album.id')->contains($inactive->id));
            $this->assertFalse($events->pluck('album.id')->contains($candidate->id));
            $this->assertFalse($events->pluck('album.id')->contains(end($otherIds)));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_freshness_uses_the_enabled_owner_account_and_disabled_listenbrainz_is_not_stale(): void
    {
        $this->preparePostgres();
        CarbonImmutable::setTestNow('2026-07-24 12:00:00+00:00');

        try {
            $owner = $this->createUser('owner@example.test');
            $library = $this->createLibrary();
            $this->createAlbum($library, 'Fresh Addition', now()->subDay());
            $this->createPlexSync($library, now()->subMinutes(5));
            [$provider, $ownerImport] = $this->createListenBrainzAccount($owner, true, now()->subHours(2));

            $activity = app(RecentCollectionActivityService::class)->forUser($owner->id);
            $this->assertSame('stale', $activity['meta']['status']);
            $this->assertTrue($activity['meta']['stale']);
            $this->assertSame($ownerImport->completed_at->toAtomString(), $activity['meta']['played_as_of']);

            $provider->update(['enabled' => false]);
            $activity = app(RecentCollectionActivityService::class)->forUser($owner->id);
            $this->assertSame('ready', $activity['meta']['status']);
            $this->assertFalse($activity['meta']['stale']);
            $this->assertNull($activity['meta']['played_as_of']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_empty_activity_reports_missing_local_freshness_explicitly(): void
    {
        $this->preparePostgres();

        $user = $this->createUser('owner@example.test');
        $activity = app(RecentCollectionActivityService::class)->forUser($user->id);

        $this->assertSame([], $activity['data']);
        $this->assertSame([
            'status' => 'empty',
            'stale' => true,
            'added_as_of' => null,
            'played_as_of' => null,
        ], $activity['meta']);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => Hash::make('not-a-real-password'),
        ]);
    }

    private function createLibrary(): PlexLibrary
    {
        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'fixture-machine',
            'machine_identifier_hash' => hash('sha256', 'fixture-machine'),
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        return PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'fixture-library',
            'title' => 'Music',
            'library_type' => 'artist',
            'last_synced_at' => now(),
        ]);
    }

    /** @return array{CatalogEntity,PlexItem} */
    private function createAlbum(PlexLibrary $library, string $title, CarbonInterface $addedAt, ?string $parentRatingKey = null, string $matchStatus = 'confirmed'): array
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
        $album = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => str($title)->slug()->toString(),
            'parent_rating_key' => $parentRatingKey,
            'item_type' => 'album',
            'title' => $title,
            'added_at_plex' => $addedAt,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        $this->match($album, $entity, 'release_group', $matchStatus);
        Holding::query()->create([
            'release_group_id' => $entity->id,
            'plex_album_item_id' => $album->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);

        return [$entity, $album];
    }

    private function createCopy(PlexLibrary $library, CatalogEntity $entity, string $title, string $ratingKey, CarbonInterface $addedAt, ?string $parentRatingKey = null, ?CarbonInterface $removedAt = null): void
    {
        $album = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => $ratingKey,
            'parent_rating_key' => $parentRatingKey,
            'item_type' => 'album',
            'title' => $title,
            'added_at_plex' => $addedAt,
            'raw_metadata' => [],
            'last_synced_at' => now(),
            'removed_at' => $removedAt,
        ]);
        $this->match($album, $entity, 'release_group');
        Holding::query()->create([
            'release_group_id' => $entity->id,
            'plex_album_item_id' => $album->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => false,
        ]);
    }

    private function match(PlexItem $item, CatalogEntity $entity, string $scope, string $status = 'confirmed'): void
    {
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $entity->id,
            'match_scope' => $scope,
            'status' => $status,
            'method' => 'external_id',
            'confidence' => 1,
        ]);
    }

    private function createPlexSync(PlexLibrary $library, CarbonInterface $completedAt): PlexSyncRun
    {
        return PlexSyncRun::query()->create([
            'plex_library_id' => $library->id,
            'status' => 'completed',
            'counts' => [],
            'started_at' => $completedAt->copy()->subMinute(),
            'completed_at' => $completedAt,
        ]);
    }

    /** @return array{SourceProvider,ListenImportRun} */
    private function createListenBrainzAccount(User $user, bool $enabled, CarbonInterface $completedAt, ?SourceProvider $provider = null): array
    {
        $provider ??= SourceProvider::query()->create([
            'slug' => 'listenbrainz',
            'display_name' => 'ListenBrainz',
            'enabled' => $enabled,
            'policy' => [],
        ]);
        $account = SourceAccount::query()->create([
            'provider_id' => $provider->id,
            'owner_user_id' => $user->id,
            'external_username' => $user->email,
            'credential_env_key' => 'LISTENBRAINZ_TOKEN',
            'cursor' => [],
            'status' => 'active',
        ]);
        $run = ListenImportRun::query()->create([
            'source_account_id' => $account->id,
            'mode' => 'incremental',
            'status' => 'completed',
            'start_cursor' => [],
            'end_cursor' => [],
            'counts' => [],
            'errors' => [],
            'started_at' => $completedAt->copy()->subMinute(),
            'completed_at' => $completedAt,
        ]);

        return [$provider, $run];
    }

    private function createEdition(User $user): void
    {
        $version = app(HomeProjectionVersion::class)->current($user->id, now()->toDateString());
        $run = RecommendationRun::query()->create([
            'user_id' => $user->id,
            'intent' => 'home_edition',
            'input' => [],
            'algorithm_version' => HomeDiscoveryService::ALGORITHM,
            'configuration_hash' => hash('sha256', 'fixture'),
            'random_seed' => 1,
            'catalog_version' => $version,
            'status' => 'completed',
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        HomeEdition::query()->create([
            'user_id' => $user->id,
            'recommendation_run_id' => $run->id,
            'version_hash' => $version,
            'algorithm_version' => HomeDiscoveryService::ALGORITHM,
            'facts_as_of' => now(),
            'generated_at' => now(),
            'payload' => [
                'feature' => null,
                'sections' => [],
                'recent_artists' => [],
                'collection' => ['artists' => 1, 'albums' => 10, 'tracks' => 0],
                'meta' => [
                    'algorithm' => HomeDiscoveryService::ALGORITHM,
                    'generated_at' => now()->toAtomString(),
                    'facts_as_of' => now()->toAtomString(),
                    'last_plex_sync_at' => now()->toAtomString(),
                    'last_listenbrainz_import_at' => null,
                    'source_coverage' => [],
                ],
            ],
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
}
