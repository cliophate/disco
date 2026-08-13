<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\Discovery\BeyondLibraryDiscoveryService;
use App\Music\Discovery\BeyondLibraryMetadataEnricher;
use App\Music\Discovery\ListenBrainzRecommendationRefresher;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class BeyondLibraryRecommendationTest extends TestCase
{
    public function test_lookup_sampling_is_stable_and_reaches_beyond_the_leading_recommendations(): void
    {
        $recommendations = [];
        for ($position = 1; $position <= 500; $position++) {
            $recommendations[] = [
                'recording_mbid' => sprintf('00000000-0000-4000-8000-%012d', $position),
                'score' => 1 - ($position / 1000),
                'latest_listened_at' => null,
            ];
        }
        $method = new \ReflectionMethod(ListenBrainzRecommendationRefresher::class, 'recommendationsForLookup');
        $refresher = app(ListenBrainzRecommendationRefresher::class);

        $first = $method->invoke($refresher, $recommendations, 100, 'stable-run-seed');
        $second = $method->invoke($refresher, $recommendations, 100, 'stable-run-seed');
        $leading = collect($recommendations)->take(100)->pluck('recording_mbid')->all();

        $this->assertCount(100, $first);
        $this->assertSame($first->pluck('recording_mbid')->all(), $second->pluck('recording_mbid')->all());
        $this->assertNotSame($leading, $first->pluck('recording_mbid')->all());
        $this->assertGreaterThanOrEqual(50, $first->filter(fn (array $recommendation): bool => ! in_array($recommendation['recording_mbid'], $leading, true))->count());
        $this->assertSame($first->pluck('score')->sortDesc()->values()->all(), $first->pluck('score')->all());
        $this->assertSame(500, config('services.listenbrainz.recommendation_count'));
        $this->assertSame(50, config('services.listenbrainz.recommendation_limit'));
        $this->assertSame(100, config('services.listenbrainz.recommendation_lookup_budget'));
    }

    public function test_recording_recommendations_admit_exact_albums_eps_and_singles(): void
    {
        $method = new \ReflectionMethod(ListenBrainzRecommendationRefresher::class, 'albumCandidate');
        $refresher = app(ListenBrainzRecommendationRefresher::class);

        foreach (['Album', 'EP', 'Single'] as $type) {
            $payload = $this->recordingPayload(
                '11111111-1111-4111-8111-111111111111',
                'Recommended Track',
                '44444444-4444-4444-8444-444444444444',
                $type,
            );
            $this->assertSame($type, data_get($method->invoke($refresher, $payload), 'release_group.primary-type'));
        }

        $payload = $this->recordingPayload('11111111-1111-4111-8111-111111111111', 'Recommended Track', '44444444-4444-4444-8444-444444444444', 'Other');
        $this->assertNull($method->invoke($refresher, $payload));
    }

    public function test_listenbrainz_recordings_become_explainable_external_album_recommendations(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        config()->set('services.listenbrainz.enabled', true);
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.username', 'fixture-user');
        config()->set('services.listenbrainz.token', 'fixture-token');
        config()->set('services.listenbrainz.rate_interval_ms', 0);
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        config()->set('services.cover_art_archive.url', 'https://coverartarchive.test');
        config()->set('services.qobuz.storefront', 'ie-en');
        $failMusicBrainz = false;
        $lastUpdated = 1783953884;
        $noContent = false;
        $emptyContent = false;
        Http::fake(function (Request $request) use (&$emptyContent, &$failMusicBrainz, &$lastUpdated, &$noContent) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/1/cf/recommendation/user/fixture-user/recording') {
                if ($noContent) {
                    return Http::response('', 204);
                }
                $recommendations = json_decode($this->fixture('recommendations.json'), true, 512, JSON_THROW_ON_ERROR);
                $recommendations['payload']['last_updated'] = $lastUpdated;
                if ($emptyContent) {
                    $recommendations['payload']['count'] = 0;
                    $recommendations['payload']['mbids'] = [];
                }

                return Http::response($recommendations, 200, ['Content-Type' => 'application/json']);
            }
            if ($failMusicBrainz && str_starts_with($path, '/ws/2/recording/')) {
                return Http::response(['error' => 'temporary failure'], 503, ['Content-Type' => 'application/json']);
            }
            if ($path === '/ws/2/recording/11111111-1111-4111-8111-111111111111') {
                return Http::response($this->recordingPayload(
                    '11111111-1111-4111-8111-111111111111',
                    'Recommended Track One',
                    '44444444-4444-4444-8444-444444444444',
                ), 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/ws/2/recording/22222222-2222-4222-8222-222222222222') {
                return Http::response($this->recordingPayload(
                    '22222222-2222-4222-8222-222222222222',
                    'Recommended Track Two',
                    '55555555-5555-4555-8555-555555555555',
                ), 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/ws/2/release/44444444-4444-4444-8444-444444444444') {
                return Http::response($this->releasePayload(), 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/ws/2/release' && $request->data()['release-group'] === '33333333-3333-4333-8333-333333333333') {
                return Http::response([
                    'releases' => [[
                        'id' => '44444444-4444-4444-8444-444444444444',
                        'status' => 'Official',
                        'date' => '2024-03-02',
                        'cover-art-archive' => ['front' => true],
                        'release-group' => ['id' => '33333333-3333-4333-8333-333333333333'],
                    ]],
                ], 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/release/44444444-4444-4444-8444-444444444444') {
                return Http::response('', 307, ['Location' => 'https://archive.org/download/mbid-44444444-4444-4444-8444-444444444444/index.json']);
            }
            if ($path === '/download/mbid-44444444-4444-4444-8444-444444444444/index.json') {
                return Http::response([
                    'release' => 'https://musicbrainz.org/release/44444444-4444-4444-8444-444444444444',
                    'images' => [[
                        'id' => '12345',
                        'approved' => true,
                        'front' => true,
                        'types' => ['Front'],
                    ]],
                ], 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/release/44444444-4444-4444-8444-444444444444/12345-1200.jpg') {
                return Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true), 200, ['Content-Type' => 'image/png']);
            }
            if ($path === '/api/v1/json/123/album-mb.php') {
                return Http::response(['album' => [[
                    'idAlbum' => '98765',
                    'strMusicBrainzID' => '33333333-3333-4333-8333-333333333333',
                    'strDescription' => 'An attributed description of the external album.',
                    'strReview' => 'Third-party review copy must not be used.',
                    'strWikipediaID' => 'External_Album',
                ]]], 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['error' => 'not found'], 404, ['Content-Type' => 'application/json']);
        });

        $existingArtist = $this->createArtist('Fixture Artist', '66666666-6666-4666-8666-666666666666');
        $guestArtist = $this->createArtist('Guest Artist', '99999999-9999-4999-8999-999999999999');
        $first = app(ListenBrainzRecommendationRefresher::class)->refresh(2, 5);
        $this->assertFalse($first['reused']);
        $this->assertSame(1, $first['candidates']);
        $this->assertSame(2, $first['recordings']);
        $this->assertSame(1, DB::table('discovery.recommendation_runs')->where('intent', 'beyond_library')->count());
        $this->assertSame(1, DB::table('discovery.recommendation_items')->count());
        $this->assertSame('album', RecommendationItem::query()->firstOrFail()->eligibility['release_type']);
        $this->assertSame(2, DB::table('discovery.recommendation_evidence')->count());
        $this->assertSame(1, DB::table('catalog.release_groups')->count());
        $this->assertSame(1, DB::table('catalog.releases')->count());
        $this->assertSame(2, DB::table('catalog.recordings')->count());
        $this->assertSame(4, DB::table('catalog.agents')->count());
        $artistId = DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.artist')
            ->where('value', '66666666-6666-4666-8666-666666666666')
            ->value('entity_id');
        $this->assertNotNull($artistId);
        $this->assertSame($existingArtist->id, $artistId);
        $this->assertSame($artistId, data_get(
            json_decode((string) DB::table('catalog.entity_metadata')->value('artist_credit'), true, 512, JSON_THROW_ON_ERROR),
            '0.artist_entity_id',
        ));
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
        $artistItem = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'fixture-artist',
            'item_type' => 'artist',
            'title' => 'Fixture Artist',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $artistItem->id,
            'entity_id' => $artistId,
            'match_scope' => 'agent',
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);

        $second = app(ListenBrainzRecommendationRefresher::class)->refresh(2, 5);
        $this->assertTrue($second['reused']);
        Http::assertSentCount(4);
        $enriched = app(BeyondLibraryMetadataEnricher::class)->enrich(5);
        $this->assertSame(1, $enriched['tracklists']);
        $this->assertSame(1, $enriched['artworks']);
        $this->assertSame(2, DB::table('catalog.medium_tracks')->count());
        $this->assertSame(1, DB::table('catalog.entity_artworks')->where('status', 'ready')->count());
        $narratives = app(AlbumNarrativeEnricher::class)->enrichLatestBeyond(5);
        $this->assertSame(1, $narratives['theaudiodb']);
        $this->assertSame('An attributed description of the external album.', DB::table('catalog.entity_narratives')->value('body'));
        $this->assertStringNotContainsString('Third-party review', (string) DB::table('catalog.entity_narratives')->value('body'));

        $lastUpdated++;
        $failMusicBrainz = true;
        try {
            app(ListenBrainzRecommendationRefresher::class)->refresh(2, 5);
            $this->fail('A transient MusicBrainz failure must not publish a partial run.');
        } catch (RequestException) {
            $this->assertSame(1, DB::table('discovery.recommendation_runs')->where('intent', 'beyond_library')->count());
        }
        $failMusicBrainz = false;
        $noContent = true;
        $noContentResult = app(ListenBrainzRecommendationRefresher::class)->refresh(2, 5);
        $this->assertSame('no_content', $noContentResult['status']);
        $this->assertTrue($noContentResult['reused']);
        $noContent = false;
        $emptyContent = true;
        $emptyResult = app(ListenBrainzRecommendationRefresher::class)->refresh(2, 5);
        $this->assertSame('no_content', $emptyResult['status']);
        $this->assertTrue($emptyResult['reused']);
        $this->assertSame(1, DB::table('discovery.recommendation_runs')->where('intent', 'beyond_library')->count());
        DB::table('discovery.recommendation_runs')->where('id', $first['run_id'])->update(['generated_at' => now()->subDays(200)]);
        Artisan::call('disco:recommendation-prune', ['--days' => 30]);
        $this->assertTrue(DB::table('discovery.recommendation_runs')->where('id', $first['run_id'])->exists());
        $providerRequestCount = Http::recorded()->count();

        $this->actingAs($owner);
        $artistResponse = $this->getJson("/api/v1/artists/{$artistId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.recommended_albums')
            ->assertJsonPath('data.recommended_albums.0.title', 'External Album')
            ->assertJsonPath('data.recommended_albums.0.owned', false);
        $albumId = $artistResponse->json('data.recommended_albums.0.id');
        $this->assertSame([$albumId], collect(app(BeyondLibraryDiscoveryService::class)->forArtist($owner->id, $guestArtist->id))->pluck('id')->all());
        $home = $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.feature.album.title', 'External Album')
            ->assertJsonPath('data.feature.album.owned', false)
            ->assertJsonPath('data.feature.lens', 'Beyond your library')
            ->assertJsonPath('data.feature.reasons.0.source', 'listenbrainz')
            ->assertJsonPath('data.collection.albums', 0)
            ->assertJsonCount(0, 'data.sections');
        Http::assertSentCount($providerRequestCount);
        $this->assertSame($albumId, $home->json('data.feature.album.id'));
        $editionId = $home->json('meta.edition_id');
        $this->assertSame(
            'https://www.qobuz.com/ie-en/search/?q=Fixture%20Artist%20%26%20Guest%20Artist%20External%20Album',
            $home->json('data.feature.album.qobuz_search_url'),
        );
        $detail = $this->getJson("/api/v1/albums/{$albumId}")
            ->assertOk()
            ->assertJsonPath('data.id', $albumId)
            ->assertJsonPath('data.owned', false)
            ->assertJsonPath('data.open_in_plex_status', 'unavailable')
            ->assertJsonPath('data.basis_plex_item_id', null)
            ->assertJsonPath('data.track_count', 2)
            ->assertJsonPath('data.tracks.0.title', 'Opening Track')
            ->assertJsonPath('data.tracks.0.featured_artists.0.name', 'Featured One')
            ->assertJsonPath('data.tracks.1.featured_artists.0.name', 'Featured One')
            ->assertJsonPath('data.tracks.1.featured_artists.1.name', 'Featured Two')
            ->assertJsonPath('data.recommendation.reasons.0.source', 'listenbrainz')
            ->assertJsonPath('data.artwork.width', 1)
            ->assertJsonPath('data.description.provider', 'theaudiodb')
            ->assertJsonPath('data.description.text', 'An attributed description of the external album.')
            ->assertJsonCount(0, 'data.holdings')
            ->assertJsonCount(2, 'data.tracks');
        $this->get($detail->json('data.artwork.url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $beyond = $this->getJson('/api/v1/beyond?shuffle=dddddddd-dddd-4ddd-8ddd-dddddddddddd')
            ->assertOk()
            ->assertJsonPath('data.0.album.id', $albumId);
        $itemId = $beyond->json('data.0.item_id');

        $this->putJson("/api/v1/home/editions/{$editionId}/recommendations/{$albumId}/feedback", [
            'action' => 'interested',
        ])->assertOk();
        $this->putJson("/api/v1/recommendations/{$itemId}/feedback", [
            'action' => 'not_for_me',
        ])->assertOk();
        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.feature', null)
            ->assertJsonCount(0, 'data.sections');
        $this->getJson("/api/v1/artists/{$artistId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.recommended_albums');
        Http::assertSentCount($providerRequestCount);
    }

    public function test_artist_projection_requires_exact_credits_and_filters_ineligible_albums(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        $artist = $this->createArtist('Target Artist', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $collaborator = $this->createArtist('Collaborator', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $run = RecommendationRun::query()->create([
            'user_id' => $owner->id,
            'intent' => 'beyond_library',
            'input' => [],
            'algorithm_version' => 'fixture-v1',
            'configuration_hash' => hash('sha256', 'artist-projection'),
            'random_seed' => 1,
            'catalog_version' => 'artist-projection',
            'status' => 'completed',
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $credits = [
            ['name' => 'Target Artist', 'artist_mbid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'artist_entity_id' => $artist->id, 'joinphrase' => ' & '],
            ['name' => 'Collaborator', 'artist_mbid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'artist_entity_id' => $collaborator->id, 'joinphrase' => ''],
        ];
        $valid = $this->createRecommendationAlbum($run, 1, 'Valid Collaboration', 'album', [], $credits);
        $owned = $this->createRecommendationAlbum($run, 2, 'Owned Album', 'album', [], $credits);
        $this->createRecommendationAlbum($run, 3, 'Compilation', 'album', ['Compilation'], $credits);
        $this->createRecommendationAlbum($run, 4, 'Single', 'single', [], $credits);
        $this->createRecommendationAlbum($run, 5, 'EP', 'ep', [], $credits);
        $suppressed = $this->createRecommendationAlbum($run, 6, 'Suppressed Album', 'album', [], $credits);
        $expired = $this->createRecommendationAlbum($run, 7, 'Expired Suppression', 'album', [], $credits);
        $unrelated = $this->createRecommendationAlbum($run, 8, 'Same Name Wrong Identity', 'album', [], [[
            'name' => 'Target Artist',
            'artist_mbid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'artist_entity_id' => $collaborator->id,
            'joinphrase' => '',
        ]]);
        $alreadyKnown = $this->createRecommendationAlbum($run, 9, 'Already Known', 'album', [], $credits);
        $wrongMatch = $this->createRecommendationAlbum($run, 10, 'Wrong Match', 'album', [], $credits);
        $interested = $this->createRecommendationAlbum($run, 11, 'Interested Album', 'album', [], $credits);
        $futureSuppression = $this->createRecommendationAlbum($run, 12, 'Future Suppression', 'album', [], $credits);

        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'projection-machine',
            'machine_identifier_hash' => hash('sha256', 'projection-machine'),
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'projection-library',
            'title' => 'Music',
            'library_type' => 'artist',
            'last_synced_at' => now(),
        ]);
        $ownedItem = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'owned-album',
            'item_type' => 'album',
            'title' => 'Owned Album',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        Holding::query()->create([
            'release_group_id' => $owned->id,
            'plex_album_item_id' => $ownedItem->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $suppressed->id,
            'action' => 'not_for_me',
        ]);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $expired->id,
            'action' => 'not_for_me',
            'expires_at' => now()->subMinute(),
        ]);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $alreadyKnown->id,
            'action' => 'already_know',
        ]);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $wrongMatch->id,
            'action' => 'wrong_match',
        ]);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $interested->id,
            'action' => 'interested',
        ]);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $futureSuppression->id,
            'action' => 'not_for_me',
            'expires_at' => now()->addHour(),
        ]);

        $recommendations = app(BeyondLibraryDiscoveryService::class)->forArtist($owner->id, $artist->id);
        $this->assertSame([$valid->id, $expired->id, $interested->id], collect($recommendations)->pluck('id')->all());
        $this->assertContains($unrelated->id, collect(app(BeyondLibraryDiscoveryService::class)->forArtist($owner->id, $collaborator->id))->pluck('id')->all());

        $ambiguousIdentifier = ExternalIdentifier::query()->create([
            'entity_id' => $artist->id,
            'namespace' => 'musicbrainz.artist',
            'value' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'status' => 'active',
        ]);
        $this->assertSame([], app(BeyondLibraryDiscoveryService::class)->forArtist($owner->id, $artist->id));
        $ambiguousIdentifier->delete();
        ExternalIdentifier::query()
            ->where('entity_id', $artist->id)
            ->where('namespace', 'musicbrainz.artist')
            ->update(['value' => 'not-a-uuid']);
        $this->assertSame([], app(BeyondLibraryDiscoveryService::class)->forArtist($owner->id, $artist->id));
    }

    public function test_beyond_browsing_is_paginated_shuffled_filtered_and_pinned_to_a_run(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        $run = $this->createRecommendationRun($owner, 'deep-run', now());
        $credits = [['name' => 'Fixture Artist', 'artist_mbid' => null, 'artist_entity_id' => null, 'joinphrase' => '']];
        $albums = collect(range(1, 27))->map(fn (int $rank): CatalogEntity => $this->createRecommendationAlbum(
            $run,
            $rank,
            "Beyond Album {$rank}",
            $rank <= 20 ? 'album' : ($rank <= 24 ? 'ep' : 'single'),
            [],
            $credits,
        ));
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $albums[25]->id,
            'action' => 'not_for_me',
        ]);
        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'browse-machine',
            'machine_identifier_hash' => hash('sha256', 'browse-machine'),
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'browse-library',
            'title' => 'Music',
            'library_type' => 'artist',
            'last_synced_at' => now(),
        ]);
        $ownedItem = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => 'owned-beyond-album',
            'item_type' => 'album',
            'title' => 'Owned Beyond Album',
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        Holding::query()->create([
            'release_group_id' => $albums[26]->id,
            'plex_album_item_id' => $ownedItem->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);
        $seed = '11111111-1111-4111-8111-111111111111';
        $otherSeed = '22222222-2222-4222-8222-222222222222';
        $url = fn (int $page, string $shuffle, ?string $runId = null, ?string $type = null): string => '/api/v1/beyond?'.http_build_query(array_filter([
            'page' => ['number' => $page, 'size' => 10],
            'shuffle' => $shuffle,
            'run_id' => $runId,
            'type' => $type,
        ]));

        $this->actingAs($owner);
        $first = $this->getJson($url(1, $seed))
            ->assertOk()
            ->assertJsonPath('meta.run_id', $run->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.eligible_total', 25)
            ->assertJsonPath('meta.filter', 'all')
            ->assertJsonPath('meta.filters.all', 25)
            ->assertJsonPath('meta.filters.album', 20)
            ->assertJsonPath('meta.filters.ep', 4)
            ->assertJsonPath('meta.filters.single', 1)
            ->assertJsonPath('meta.run_total', 27);
        $this->assertCount(10, $first->json('data'));
        $this->assertStringContainsString('run_id=', $first->json('links.next'));
        $this->assertSame($first->json('data'), $this->getJson($url(1, $seed, $run->id))->assertOk()->json('data'));
        $this->assertSame(count($first->json('data')), DB::table('discovery.recommendation_impressions')->where('surface', 'beyond')->count());
        $firstOrder = collect();
        $secondOrder = collect();
        foreach (range(1, 3) as $page) {
            $firstOrder->push(...$this->getJson($url($page, $seed, $run->id))->assertOk()->json('data.*.entity_id'));
            $secondOrder->push(...$this->getJson($url($page, $otherSeed, $run->id))->assertOk()->json('data.*.entity_id'));
        }
        $this->assertCount(25, $firstOrder);
        $this->assertCount(25, $firstOrder->unique());
        $this->assertEqualsCanonicalizing($albums->take(25)->pluck('id')->all(), $firstOrder->all());
        $this->assertNotContains($albums[25]->id, $firstOrder);
        $this->assertNotContains($albums[26]->id, $firstOrder);
        $this->assertEqualsCanonicalizing($firstOrder->all(), $secondOrder->all());
        $this->assertNotSame($firstOrder->all(), $secondOrder->all());
        $ep = $this->getJson($url(1, $seed, $run->id, 'ep'))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.filter', 'ep')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.eligible_total', 25);
        $this->assertStringContainsString('type=ep', $ep->json('links.first'));
        $this->assertEqualsCanonicalizing($albums->slice(20, 4)->pluck('id')->all(), $ep->json('data.*.entity_id'));
        $this->getJson($url(1, $seed, $run->id, 'invalid'))->assertUnprocessable();

        $smallRun = $this->createRecommendationRun($owner, 'small-run', now()->addMinute());
        foreach (range(1, 3) as $rank) {
            $this->createRecommendationAlbum($smallRun, $rank, "New Album {$rank}", 'album', [], $credits);
        }
        $this->getJson($url(1, $seed))
            ->assertOk()
            ->assertJsonPath('meta.run_id', $smallRun->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.eligible_total', 3)
            ->assertJsonPath('links.next', null);
        $this->getJson($url(1, $seed, $run->id))
            ->assertOk()
            ->assertJsonPath('meta.run_id', $run->id)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.eligible_total', 25)
            ->assertJsonPath('meta.run_total', 27);

        $this->expectException(NotFoundHttpException::class);
        app(BeyondLibraryDiscoveryService::class)->browseForUser(
            '44444444-4444-4444-8444-444444444444',
            1,
            10,
            $seed,
            $run->id,
        );
    }

    public function test_empty_beyond_browsing_has_coherent_pagination(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/beyond?shuffle=33333333-3333-4333-8333-333333333333')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.run_id', null)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.eligible_total', 0)
            ->assertJsonPath('links.next', null);
        $this->getJson('/api/v1/beyond')->assertUnprocessable();

        $run = $this->createRecommendationRun($owner, 'filtered-empty', now());
        $album = $this->createRecommendationAlbum($run, 1, 'Filtered Album', 'album', [], []);
        RecommendationFeedback::query()->create([
            'user_id' => $owner->id,
            'entity_id' => $album->id,
            'action' => 'wrong_match',
        ]);
        $this->getJson('/api/v1/beyond?shuffle=33333333-3333-4333-8333-333333333333')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.run_id', $run->id)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.eligible_total', 0)
            ->assertJsonPath('meta.run_total', 1)
            ->assertJsonPath('meta.last_page', 1);
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

    private function createArtist(string $name, string $mbid): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'agent',
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        Agent::query()->create(['entity_id' => $entity->id, 'agent_type' => 'other']);
        ExternalIdentifier::query()->create([
            'entity_id' => $entity->id,
            'namespace' => 'musicbrainz.artist',
            'value' => $mbid,
            'status' => 'active',
        ]);

        return $entity;
    }

    private function createRecommendationRun(User $user, string $version, mixed $generatedAt): RecommendationRun
    {
        return RecommendationRun::query()->create([
            'user_id' => $user->id,
            'intent' => 'beyond_library',
            'input' => [],
            'algorithm_version' => 'fixture-v1',
            'configuration_hash' => hash('sha256', $version),
            'random_seed' => 1,
            'catalog_version' => $version,
            'status' => 'completed',
            'generated_at' => $generatedAt,
            'expires_at' => now()->addDay(),
        ]);
    }

    /** @param list<string> $secondaryTypes
     * @param  list<array<string, mixed>>  $credits
     */
    private function createRecommendationAlbum(RecommendationRun $run, int $rank, string $title, string $primaryType, array $secondaryTypes, array $credits): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => $title,
            'sort_name' => $title,
        ]);
        ReleaseGroup::query()->create([
            'entity_id' => $entity->id,
            'primary_type' => $primaryType,
            'secondary_types' => $secondaryTypes,
            'date_precision' => 'unknown',
        ]);
        EntityMetadata::query()->create([
            'entity_id' => $entity->id,
            'source_provider' => 'musicbrainz',
            'primary_type' => ucfirst($primaryType),
            'artist_credit' => $credits,
            'enriched_at' => now(),
        ]);
        RecommendationItem::query()->create([
            'run_id' => $run->id,
            'entity_id' => $entity->id,
            'rank' => $rank,
            'score' => max(0, 1 - ($rank / 100)),
            'component_scores' => [],
            'eligibility' => ['scope' => 'external'],
            'module_type' => 'beyond-library',
            'explanation_text' => 'Recommended by fixture.',
            'explanation_version' => 'fixture-v1',
        ]);

        return $entity;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/listenbrainz/{$name}"));
    }

    /** @return array<string, mixed> */
    private function recordingPayload(string $recordingMbid, string $trackTitle, string $releaseMbid, string $primaryType = 'Album'): array
    {
        return [
            'id' => $recordingMbid,
            'title' => $trackTitle,
            'length' => 180000,
            'artist-credit' => $this->trackArtistCredits($recordingMbid === '77777777-7777-4777-8777-777777777777' ? 1 : 2),
            'releases' => [[
                'id' => $releaseMbid,
                'title' => 'External Album',
                'status' => 'Official',
                'date' => '2024-03-02',
                'country' => 'XW',
                'artist-credit' => $this->artistCredits(),
                'release-group' => [
                    'id' => '33333333-3333-4333-8333-333333333333',
                    'title' => 'External Album',
                    'primary-type' => $primaryType,
                    'secondary-types' => [],
                    'first-release-date' => '2024-03-02',
                    'artist-credit' => $this->artistCredits(),
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function releasePayload(): array
    {
        return [
            'id' => '44444444-4444-4444-8444-444444444444',
            'title' => 'External Album',
            'status' => 'Official',
            'date' => '2024-03-02',
            'country' => 'XW',
            'release-group' => ['id' => '33333333-3333-4333-8333-333333333333'],
            'media' => [[
                'position' => 1,
                'format' => 'Digital Media',
                'tracks' => [[
                    'position' => 1,
                    'number' => '1',
                    'title' => 'Opening Track',
                    'length' => 180000,
                    'recording' => [
                        'id' => '77777777-7777-4777-8777-777777777777',
                        'title' => 'Opening Track',
                        'length' => 180000,
                        'artist-credit' => $this->trackArtistCredits(1),
                    ],
                ], [
                    'position' => 2,
                    'number' => '2',
                    'title' => 'Closing Track',
                    'length' => 210000,
                    'recording' => [
                        'id' => '88888888-8888-4888-8888-888888888888',
                        'title' => 'Closing Track',
                        'length' => 210000,
                        'artist-credit' => $this->trackArtistCredits(2),
                    ],
                ]],
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function artistCredits(): array
    {
        return [[
            'name' => 'Fixture Artist',
            'joinphrase' => ' & ',
            'artist' => ['id' => '66666666-6666-4666-8666-666666666666', 'name' => 'Fixture Artist'],
        ], [
            'name' => 'Guest Artist',
            'joinphrase' => '',
            'artist' => ['id' => '99999999-9999-4999-8999-999999999999', 'name' => 'Guest Artist'],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function trackArtistCredits(int $featured): array
    {
        return [
            ...$this->artistCredits(),
            [
                'name' => 'Featured One',
                'joinphrase' => $featured > 1 ? ' & ' : '',
                'artist' => ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Featured One'],
            ],
            ...($featured > 1 ? [[
                'name' => 'Featured Two',
                'joinphrase' => '',
                'artist' => ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'name' => 'Featured Two'],
            ]] : []),
        ];
    }
}
