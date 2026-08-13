<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\Recording;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\SourceAccount;
use App\Models\User;
use App\Music\Discovery\HomeDiscoveryService;
use App\Music\Library\AlbumFactsService;
use App\Music\ListenBrainz\ListenBrainzImporter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ListenBrainzImporterTest extends TestCase
{
    public function test_full_import_is_idempotent_and_matches_only_exact_catalog_and_plex_entities(): void
    {
        $this->preparePostgres();
        $this->seedExactMatches();
        $this->fakeBaselinePages();

        $first = app(ListenBrainzImporter::class)->import(full: true);

        $this->assertSame('completed', $first['status']);
        $this->assertSame(4, $first['requested']);
        $this->assertSame(3, $first['inserted']);
        $this->assertSame(1, $first['existing']);
        $this->assertSame(3, DB::table('activity.listening_events')->count());
        $this->assertSame(3, DB::table('activity.listening_event_matches')->count());
        $this->assertSame(1, DB::table('activity.listening_event_matches')->where('status', 'matched')->count());
        $this->assertSame(1, DB::table('activity.listening_event_matches')->where('status', 'unmatched')->count());
        $this->assertSame(1, DB::table('activity.listening_event_matches')->where('status', 'conflict')->count());
        $this->assertNotNull(DB::table('activity.listening_event_matches')->where('status', 'matched')->value('plex_track_item_id'));
        $this->assertNull(DB::table('activity.listening_event_matches')->where('status', 'conflict')->value('release_group_entity_id'));
        $this->assertSame(1, DB::table('activity.play_aggregates')->value('play_count'));
        $this->assertSame(3, DB::table('source.snapshots')->count());
        $albums = PlexItem::query()->where('item_type', 'album')->with('matches')->get();
        $albumFacts = app(AlbumFactsService::class)->forAlbums($albums);
        $this->assertSame(1, $albumFacts[$this->albumKey($albums, 'album-1')]['listenbrainz']['listen_count']);
        $this->assertSame('listenbrainz', $albumFacts[$this->albumKey($albums, 'album-1')]['last_heard_source']);
        $home = app(HomeDiscoveryService::class)->build();
        $this->assertSame('daily-feature-lenses-v9', data_get($home, 'meta.algorithm'));
        $this->assertSame(0.5, data_get($home, 'meta.source_coverage.listenbrainz'));
        $aggregateUpdatedAt = (string) DB::table('activity.play_aggregates')->value('updated_at');
        $activityRevision = (int) data_get(SourceAccount::query()->first()?->cursor, 'activity_revision');

        $second = app(ListenBrainzImporter::class)->import(full: true);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(4, $second['existing']);
        $this->assertSame(3, DB::table('activity.listening_events')->count());
        $this->assertSame(3, DB::table('source.snapshots')->count());
        $this->assertSame($aggregateUpdatedAt, (string) DB::table('activity.play_aggregates')->value('updated_at'));
        $this->assertSame($activityRevision, (int) data_get(SourceAccount::query()->first()?->cursor, 'activity_revision'));

        $eventId = DB::table('activity.listening_events')->value('id');
        try {
            DB::table('activity.listening_events')->where('id', $eventId)->update(['supplied_track' => 'mutated']);
            $this->fail('Listening event updates must be rejected by PostgreSQL.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            DB::table('activity.listening_events')->where('id', $eventId)->delete();
            $this->fail('Listening event deletes must be rejected by PostgreSQL.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function test_incomplete_and_failed_full_scans_never_reconcile_unseen_projections(): void
    {
        $this->preparePostgres();
        $this->seedExactMatches();
        $this->fakeBaselinePages();
        app(ListenBrainzImporter::class)->import(full: true);

        $unmatchedEventId = DB::table('activity.listening_events')
            ->where('supplied_track', 'Unmatched Track')
            ->value('id');
        $page = $this->jsonFixture('listens-page-1.json');
        $newListen = $page['payload']['listens'][0];
        $newListen['listened_at'] = 170;
        $newListen['track_metadata']['track_name'] = 'New Unmatched Track';
        $newListen['track_metadata']['additional_info'] = [
            'recording_msid' => '99999999-9999-4999-8999-999999999999',
        ];
        $page['payload']['listens'] = [$page['payload']['listens'][0], $newListen];
        $this->fakeSinglePage($page);

        $incomplete = app(ListenBrainzImporter::class)->import(full: true, maxPages: 1);
        $this->assertSame('incomplete', $incomplete['status']);
        $this->assertTrue((bool) DB::table('activity.listening_event_matches')
            ->where('listening_event_id', $unmatchedEventId)
            ->value('source_present'));

        $this->resetHttp();
        Http::fake(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/1/validate-token') {
                return Http::response($this->jsonFixture('validate-token.json'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['error' => 'fixture failure'], 503, ['Content-Type' => 'application/json']);
        });
        try {
            app(ListenBrainzImporter::class)->import(full: true);
            $this->fail('A provider failure must fail the import.');
        } catch (Throwable) {
            $this->assertTrue((bool) DB::table('activity.listening_event_matches')
                ->where('listening_event_id', $unmatchedEventId)
                ->value('source_present'));
        }

        $reduced = $this->jsonFixture('listens-page-1.json');
        $reduced['payload']['listens'] = [$reduced['payload']['listens'][0]];
        $this->fakeSinglePage($reduced);
        app(ListenBrainzImporter::class)->import(full: true);

        $this->assertFalse((bool) DB::table('activity.listening_event_matches')
            ->where('listening_event_id', $unmatchedEventId)
            ->value('source_present'));
        $this->assertSame(1, DB::table('activity.listening_event_matches')->where('source_present', true)->count());
    }

    public function test_incremental_import_pages_forward_without_advancing_past_backlog(): void
    {
        $this->preparePostgres();
        config()->set('services.listenbrainz.overlap_seconds', 0);
        $page = function (array $timestamps): array {
            return [
                'payload' => [
                    'count' => count($timestamps),
                    'user_id' => 'fixture-user',
                    'listens' => array_map(fn (int $timestamp): array => [
                        'listened_at' => $timestamp,
                        'recording_msid' => sprintf('%08d-0000-4000-8000-%012d', $timestamp, $timestamp),
                        'track_metadata' => [
                            'artist_name' => 'Incremental Artist',
                            'release_name' => 'Incremental Album',
                            'track_name' => "Track {$timestamp}",
                        ],
                    ], $timestamps),
                ],
            ];
        };
        $requestedMins = [];
        $this->resetHttp();
        Http::fake(function (Request $request) use (&$requestedMins, $page) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/1/validate-token') {
                return Http::response($this->jsonFixture('validate-token.json'), 200, ['Content-Type' => 'application/json']);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $min = (int) ($query['min_ts'] ?? -1);
            $requestedMins[] = $min;
            $payload = match ($min) {
                0 => $page([110, 100]),
                109 => $page([120, 110]),
                119 => $page([130]),
                default => $page([]),
            };

            return Http::response($payload, 200, ['Content-Type' => 'application/json']);
        });

        $result = app(ListenBrainzImporter::class)->import();

        $this->assertSame('completed', $result['status']);
        $this->assertSame([0, 109, 119], $requestedMins);
        $this->assertSame(4, $result['inserted']);
        $this->assertSame(4, DB::table('activity.listening_events')->count());
        $this->assertSame(130, (int) data_get(SourceAccount::query()->first()?->cursor, 'latest_listened_at'));
    }

    public function test_an_unowned_submitted_release_never_falls_back_to_an_owned_recording_album(): void
    {
        $this->preparePostgres();
        $this->seedExactMatches();
        $page = $this->jsonFixture('listens-page-1.json');
        $listen = $page['payload']['listens'][0];
        $listen['track_metadata']['additional_info']['release_mbid'] = '99999999-9999-4999-8999-999999999999';
        $page['payload']['listens'] = [$listen];
        $this->fakeSinglePage($page);

        app(ListenBrainzImporter::class)->import(full: true);

        $match = DB::table('activity.listening_event_matches')->first();
        $this->assertSame('unmatched', $match->status);
        $this->assertNotNull($match->recording_entity_id);
        $this->assertNull($match->release_group_entity_id);
        $this->assertSame(0, DB::table('activity.play_aggregates')->count());
    }

    public function test_a_release_group_mbid_resolves_directly_to_the_canonical_album(): void
    {
        $this->preparePostgres();
        $this->seedExactMatches();
        $page = $this->jsonFixture('listens-page-1.json');
        $listen = $page['payload']['listens'][0];
        unset($listen['track_metadata']['additional_info']['release_mbid']);
        $listen['track_metadata']['additional_info']['release_group_mbid'] = '33333333-3333-4333-8333-333333333333';
        $page['payload']['listens'] = [$listen];
        $this->fakeSinglePage($page);

        app(ListenBrainzImporter::class)->import(full: true);

        $match = DB::table('activity.listening_event_matches')->first();
        $releaseGroupId = DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release_group')
            ->value('entity_id');
        $this->assertSame('matched', $match->status);
        $this->assertSame($releaseGroupId, $match->release_group_entity_id);
        $this->assertSame('musicbrainz_exact', $match->method);
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
        User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.username', 'fixture-user');
        config()->set('services.listenbrainz.token', 'fixture-secret-token');
        config()->set('services.listenbrainz.enabled', true);
        config()->set('services.listenbrainz.page_size', 2);
        config()->set('services.listenbrainz.overlap_seconds', 3600);
        config()->set('services.listenbrainz.rate_interval_ms', 0);
        config()->set('services.listenbrainz.user_agent', 'Disco tests (offline fixtures)');
        Http::preventStrayRequests();
    }

    private function seedExactMatches(): void
    {
        $releaseGroupOne = $this->entity('release_group', 'Matched Album');
        $releaseGroupTwo = $this->entity('release_group', 'Other Album');
        ReleaseGroup::query()->create(['entity_id' => $releaseGroupOne->id, 'primary_type' => 'album']);
        ReleaseGroup::query()->create(['entity_id' => $releaseGroupTwo->id, 'primary_type' => 'album']);
        $releaseOne = $this->entity('release', 'Matched Album Edition');
        Release::query()->create([
            'entity_id' => $releaseOne->id,
            'release_group_id' => $releaseGroupOne->id,
            'status' => 'official',
        ]);
        $recordingOne = $this->entity('recording', 'Exact Track');
        $recordingTwo = $this->entity('recording', 'Conflicting Track');
        Recording::query()->create(['entity_id' => $recordingOne->id]);
        Recording::query()->create(['entity_id' => $recordingTwo->id]);
        ExternalIdentifier::query()->create([
            'entity_id' => $releaseOne->id,
            'namespace' => 'musicbrainz.release',
            'value' => '22222222-2222-4222-8222-222222222222',
            'status' => 'active',
        ]);
        ExternalIdentifier::query()->create([
            'entity_id' => $releaseGroupOne->id,
            'namespace' => 'musicbrainz.release_group',
            'value' => '33333333-3333-4333-8333-333333333333',
            'status' => 'active',
        ]);
        ExternalIdentifier::query()->create([
            'entity_id' => $recordingOne->id,
            'namespace' => 'musicbrainz.recording',
            'value' => '11111111-1111-4111-8111-111111111111',
            'status' => 'active',
        ]);
        ExternalIdentifier::query()->create([
            'entity_id' => $recordingTwo->id,
            'namespace' => 'musicbrainz.recording',
            'value' => '44444444-4444-4444-8444-444444444444',
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
        $albumOne = $this->plexItem($library, 'album-1', 'album', null, 'Matched Album');
        $albumTwo = $this->plexItem($library, 'album-2', 'album', null, 'Other Album');
        $trackOne = $this->plexItem($library, 'track-1', 'track', 'album-1', 'Exact Track');
        $trackTwo = $this->plexItem($library, 'track-2', 'track', 'album-2', 'Conflicting Track');
        $this->plexMatch($albumOne, $releaseGroupOne->id, 'release_group');
        $this->plexMatch($albumOne, $releaseOne->id, 'release');
        $this->plexMatch($albumTwo, $releaseGroupTwo->id, 'release_group');
        $this->plexMatch($trackOne, $recordingOne->id, 'recording');
        $this->plexMatch($trackTwo, $recordingTwo->id, 'recording');
    }

    private function fakeBaselinePages(): void
    {
        $this->resetHttp();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/1/validate-token') {
                return Http::response($this->jsonFixture('validate-token.json'), 200, ['Content-Type' => 'application/json']);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $payload = match ((int) ($query['max_ts'] ?? -1)) {
                -1 => $this->jsonFixture('listens-page-1.json'),
                191 => $this->jsonFixture('listens-page-2.json'),
                default => $this->jsonFixture('listens-empty.json'),
            };

            return Http::response($payload, 200, ['Content-Type' => 'application/json']);
        });
    }

    /** @param array<string, mixed> $page */
    private function fakeSinglePage(array $page): void
    {
        $this->resetHttp();
        Http::fake(function (Request $request) use ($page) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/1/validate-token') {
                return Http::response($this->jsonFixture('validate-token.json'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response($page, 200, ['Content-Type' => 'application/json']);
        });
    }

    private function entity(string $kind, string $name): CatalogEntity
    {
        return CatalogEntity::query()->create([
            'kind' => $kind,
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
    }

    private function plexItem(PlexLibrary $library, string $ratingKey, string $type, ?string $parent, string $title): PlexItem
    {
        return PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => $ratingKey,
            'item_type' => $type,
            'parent_rating_key' => $parent,
            'title' => $title,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
    }

    private function plexMatch(PlexItem $item, string $entityId, string $scope): void
    {
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $entityId,
            'match_scope' => $scope,
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);
    }

    /** @param Collection<int, PlexItem> $albums */
    private function albumKey($albums, string $ratingKey): string
    {
        $album = $albums->firstWhere('rating_key', $ratingKey);

        return "{$album->plex_library_id}:{$album->rating_key}";
    }

    /** @return array<string, mixed> */
    private function jsonFixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path("tests/Fixtures/listenbrainz/{$name}")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }
}
