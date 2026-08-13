<?php

namespace Tests\Feature;

use App\Models\EntityMetadata;
use App\Models\EntityNarrative;
use App\Models\ExternalIdentifier;
use App\Models\PlexItem;
use App\Models\PlexItemArtwork;
use App\Models\User;
use App\Music\Artwork\PlexArtworkIngestor;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\Descriptions\ArtistBiographyEnricher;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use App\Music\Plex\PlexSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PostgresVerticalSliceTest extends TestCase
{
    public function test_postgres_migrations_sync_and_core_apis_work_together(): void
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
        config()->set('services.plex.url', 'https://plex.test');
        config()->set('services.plex.token', 'fixture-token');
        config()->set('services.plex.library', 'Music');
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        config()->set('services.plex.expected_library_uuid', 'fixture-library');
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.artwork_auto_ingest', false);
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        Storage::fake('artwork');

        $artistPayload = $this->fixture('artists.xml');
        $albumPayload = str_replace(
            '    <Guid id="mbid://22222222-2222-4222-8222-222222222222" />'.PHP_EOL,
            '',
            $this->fixture('albums.xml'),
        );
        $trackPayload = $this->fixture('tracks.xml');
        $failTracks = false;
        $musicbrainzDate = '2021-09-10';
        Http::fake(function (Request $request) use (&$albumPayload, &$artistPayload, &$failTracks, &$musicbrainzDate, &$trackPayload) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/identity') {
                return Http::response($this->fixture('identity.xml'));
            }
            if ($path === '/library/sections') {
                return Http::response($this->fixture('sections.xml'));
            }
            if ($path === '/library/metadata/201/thumb/1700000000') {
                return Http::response($this->imageFixture(), 200, ['Content-Type' => 'image/png']);
            }
            if ($path === '/ws/2/release/22222222-2222-4222-8222-222222222222') {
                return Http::response([
                    'id' => '22222222-2222-4222-8222-222222222222',
                    'release-group' => [
                        'id' => '33333333-3333-4333-8333-333333333333',
                        'primary-type' => 'Album',
                        'first-release-date' => $musicbrainzDate,
                        'artist-credit' => [['name' => 'Little Simz', 'artist' => ['id' => '11111111-1111-4111-8111-111111111111']]],
                    ],
                    'artist-credit' => [['name' => 'Little Simz', 'artist' => ['id' => '11111111-1111-4111-8111-111111111111']]],
                    'relations' => [[
                        'target-type' => 'url',
                        'type' => 'purchase for download',
                        'url' => ['resource' => 'https://open.qobuz.com/album/0886445885030'],
                    ]],
                    'label-info' => [
                        ['label' => ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Age 101'], 'catalog-number' => 'AGE-101'],
                        ['label' => ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Epic'], 'catalog-number' => '[none]'],
                    ],
                    'media' => [[
                        'position' => 1,
                        'format' => 'Digital Media',
                        'tracks' => [[
                            'position' => 1,
                            'number' => '1',
                            'title' => 'Introvert',
                            'length' => 360000,
                        ]],
                    ]],
                ], 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/api/v1/json/123/album-mb.php') {
                return Http::response(['album' => [[
                    'idAlbum' => '12345',
                    'strMusicBrainzID' => '33333333-3333-4333-8333-333333333333',
                    'strDescription' => 'An attributed description of an owned album.',
                ]]], 200, ['Content-Type' => 'application/json']);
            }
            if ($path === '/api/v1/json/123/artist-mb.php') {
                return Http::response(['artists' => [[
                    'idArtist' => '67890',
                    'strMusicBrainzID' => '11111111-1111-4111-8111-111111111111',
                    'strBiography' => 'An attributed biography of an owned artist.',
                ]]], 200, ['Content-Type' => 'application/json']);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $type = (int) ($query['type'] ?? 0);
            if ($type === 10 && $failTracks) {
                return Http::response('', 503);
            }

            return Http::response(match ($type) {
                8 => $artistPayload,
                9 => $albumPayload,
                10 => $trackPayload,
                default => '<MediaContainer />',
            });
        });

        $counts = app(PlexSyncService::class)->sync();
        $this->assertSame(1, $counts['albums']);
        $this->assertSame(3, DB::table('catalog.entities')->count());
        $this->assertSame(3, DB::table('library.plex_items')->count());
        $this->assertSame(3, DB::table('source.snapshots')->count());
        $this->assertSame(0, DB::table('catalog.releases')->count());
        $this->assertSame(0, DB::table('catalog.media')->count());
        $this->assertSame(0, DB::table('catalog.medium_tracks')->count());
        $this->assertSame(1, DB::table('library.holdings')->count());
        $this->assertNull(DB::table('library.holdings')->value('release_id'));
        $this->assertSame(9, DB::table('source.assertions')->count());
        $this->assertSame(1, DB::table('library.plex_sync_runs')->count());
        $this->assertSame(1, DB::table('library.plex_media_parts')->count());
        $this->assertSame('flac', DB::table('library.plex_media_parts')->value('audio_codec'));
        $mediaPartId = DB::table('library.plex_media_parts')->value('id');

        $candidateAlbumId = DB::table('library.plex_entity_matches')
            ->where('match_scope', 'release_group')
            ->where('status', 'candidate')
            ->value('entity_id');
        $this->assertNotNull($candidateAlbumId);

        app(PlexSyncService::class)->sync();
        $this->assertSame($mediaPartId, DB::table('library.plex_media_parts')->value('id'));
        $this->assertSame(3, DB::table('catalog.entities')->count());
        $this->assertSame(3, DB::table('source.snapshots')->count());
        $this->assertSame(3, DB::table('library.plex_entity_matches')->count());
        $this->assertSame($candidateAlbumId, DB::table('library.plex_entity_matches')
            ->where('match_scope', 'release_group')
            ->where('status', 'candidate')
            ->value('entity_id'));

        $albumPayload = $this->fixture('albums.xml');
        app(PlexSyncService::class)->sync();
        $this->assertSame($candidateAlbumId, DB::table('library.plex_entity_matches')
            ->where('match_scope', 'release_group')
            ->where('status', 'confirmed')
            ->value('entity_id'));
        $releaseId = DB::table('library.plex_entity_matches')
            ->where('match_scope', 'release')
            ->where('status', 'confirmed')
            ->value('entity_id');
        $this->assertNotNull($releaseId);
        $this->assertSame(4, DB::table('catalog.entities')->count());
        $this->assertSame(1, DB::table('catalog.releases')->count());
        $this->assertSame($candidateAlbumId, DB::table('catalog.releases')->where('entity_id', $releaseId)->value('release_group_id'));
        $this->assertSame($releaseId, DB::table('library.holdings')->value('release_id'));

        $failTracks = true;
        try {
            app(PlexSyncService::class)->sync();
            $this->fail('A failed Plex response should abort synchronization.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }
        $failTracks = false;
        $this->assertSame(0, DB::table('library.plex_items')->whereNotNull('removed_at')->count());
        $this->assertSame(3, DB::table('library.plex_sync_runs')->count());

        $artistPayload = $albumPayload = $trackPayload = '<MediaContainer size="0" totalSize="0" />';
        try {
            app(PlexSyncService::class)->sync();
            $this->fail('An anomalous empty scan should not tombstone an existing library.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('empty Plex artist response', $exception->getMessage());
        }
        $artistPayload = $this->fixture('artists.xml');
        $albumPayload = $this->fixture('albums.xml');
        $trackPayload = $this->fixture('tracks.xml');
        $this->assertSame(0, DB::table('library.plex_items')->whereNotNull('removed_at')->count());
        $this->assertSame(3, DB::table('library.plex_sync_runs')->count());

        $trackId = DB::table('library.plex_items')->where('item_type', 'track')->value('id');
        $trackPayload = '<MediaContainer size="0" totalSize="0" />';
        try {
            app(PlexSyncService::class)->sync();
            $this->fail('An empty track response should require an explicit reconciliation override.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('empty Plex track response', $exception->getMessage());
        }
        $this->assertNull(DB::table('library.plex_items')->where('id', $trackId)->value('removed_at'));
        app(PlexSyncService::class)->sync(allowEmptyTypes: true);
        $this->assertNotNull(DB::table('library.plex_items')->where('id', $trackId)->value('removed_at'));

        $trackPayload = $this->fixture('tracks.xml');
        app(PlexSyncService::class)->sync();
        $this->assertNull(DB::table('library.plex_items')->where('id', $trackId)->value('removed_at'));

        $albumItem = PlexItem::query()->where('item_type', 'album')->firstOrFail();
        $artwork = app(PlexArtworkIngestor::class)->ingest($albumItem);
        $this->assertSame('ready', $artwork->status);
        Storage::disk('artwork')->assertExists($artwork->storage_key);
        $duplicateArtwork = PlexItemArtwork::query()->create([
            'plex_item_id' => $trackId,
            'status' => 'ready',
            'content_sha256' => $artwork->content_sha256,
            'storage_key' => $artwork->storage_key,
            'mime_type' => $artwork->mime_type,
            'size_bytes' => $artwork->size_bytes,
            'width' => $artwork->width,
            'height' => $artwork->height,
        ]);
        $this->assertSame($artwork->storage_key, $duplicateArtwork->storage_key);
        $missingArtwork = app(PlexArtworkIngestor::class)->ingest(PlexItem::query()->findOrFail($trackId));
        $this->assertSame('missing', $missingArtwork->status);
        $this->assertNull($missingArtwork->storage_key);
        $missingArtwork->delete();

        $releaseIdentifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release')
            ->where('value', '22222222-2222-4222-8222-222222222222')
            ->firstOrFail();
        $this->assertSame($releaseId, $releaseIdentifier->entity_id);
        $metadata = app(MusicBrainzEnricher::class)->enrich($releaseIdentifier);
        $this->assertSame('Age 101', $metadata->attributes['labels'][0]['name']);
        $this->assertNull($metadata->attributes['labels'][1]['catalog_number']);
        $this->assertSame('day', $metadata->first_release_precision);
        $this->assertSame(10, $metadata->first_release_day);
        $this->assertDatabaseHas('catalog.external_identifiers', [
            'entity_id' => $candidateAlbumId,
            'namespace' => 'musicbrainz.release_group',
            'value' => '33333333-3333-4333-8333-333333333333',
        ]);
        $musicbrainzDate = '2021-09';
        $metadata = app(MusicBrainzEnricher::class)->enrich($releaseIdentifier);
        $this->assertSame('month', $metadata->first_release_precision);
        $this->assertNull($metadata->first_release_day);
        $this->assertGreaterThan(0, DB::table('source.canonical_field_choices')->count());

        $narratives = app(AlbumNarrativeEnricher::class)->enrichOwned(1);
        $this->assertSame(['requested' => 1, 'theaudiodb' => 1, 'wikipedia' => 0, 'missing' => 0, 'failed' => 0], $narratives);
        $providerRequestCount = Http::recorded()->count();
        $this->assertSame(0, app(AlbumNarrativeEnricher::class)->enrichOwned(1)['requested']);
        $this->assertSame($providerRequestCount, Http::recorded()->count());
        $this->assertSame(0, Artisan::call('disco:album-narratives', ['--scope' => 'owned', '--limit' => 1]));
        $this->assertStringContainsString('requested', Artisan::output());
        $artistNarratives = app(ArtistBiographyEnricher::class)->enrichOwned(1);
        $this->assertSame(['requested' => 1, 'theaudiodb' => 1, 'wikipedia' => 0, 'missing' => 0, 'failed' => 0], $artistNarratives);

        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $unauthenticatedPlexItemId = PlexItem::query()->where('item_type', 'album')->value('id');
        $this->getJson("/api/v1/plex/open-target/{$unauthenticatedPlexItemId}")->assertUnauthorized();
        $this->actingAs($owner);

        $library = $this->getJson('/api/v1/library/albums')->assertOk();
        $library->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.filters.all', 1)
            ->assertJsonPath('meta.filter', 'all')
            ->assertJsonPath('meta.sort', 'name');
        $library->assertJsonPath('data.0.open_in_plex_status', 'exact');
        $this->getJson('/api/v1/library/albums?page[number]=999&page[size]=10&type=album&sort=-name')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1);
        $albumId = $library->json('data.0.id');
        $plexItemId = $library->json('data.0.plex_item_id');
        $artworkUrl = $library->json('data.0.artwork.url');
        $this->assertIsString($artworkUrl);
        $artworkResponse = $this->get($artworkUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $this->withHeader('If-None-Match', $artworkResponse->headers->get('ETag'))
            ->get($artworkUrl)
            ->assertStatus(304);

        $this->getJson("/api/v1/albums/{$albumId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Sometimes I Might Be Introvert')
            ->assertJsonPath('data.tracks.0.title', 'Introvert')
            ->assertJsonPath('data.metadata_status', 'enriched')
            ->assertJsonPath('data.first_release_date.precision', 'month')
            ->assertJsonPath('data.description.provider', 'theaudiodb')
            ->assertJsonPath('data.description.text', 'An attributed description of an owned album.')
            ->assertJsonPath('data.labels.0.name', 'Age 101')
            ->assertJsonPath('data.labels.1.catalog_number', null)
            ->assertJsonPath('data.qobuz.status', 'exact')
            ->assertJsonPath('data.qobuz.url', 'https://open.qobuz.com/album/0886445885030')
            ->assertJsonPath('data.basis_release_id', $releaseId)
            ->assertJsonPath('data.tracks.0.playback.sources.0.mime_type', 'audio/flac')
            ->assertJsonStructure(['data' => ['basis_release_id', 'formats', 'holdings' => [['id', 'release_id', 'plex_item_id', 'formats', 'edition_summary']]]]);
        $artistId = DB::table('library.plex_entity_matches')
            ->where('match_scope', 'agent')
            ->where('status', 'confirmed')
            ->value('entity_id');
        EntityMetadata::query()->updateOrCreate(
            ['entity_id' => $artistId],
            [
                'source_provider' => 'musicbrainz',
                'external_links' => [
                    ['type' => 'official homepage', 'url' => 'https://www.littlesimz.com/'],
                    ['type' => 'other databases', 'url' => 'https://en.wikipedia.org/wiki/Little_Simz'],
                    ['type' => 'other databases', 'url' => 'https://www.discogs.com/artist/3425528-Little-Simz'],
                    ['type' => 'purchase for download', 'url' => 'https://littlesimz.bandcamp.com/'],
                    ['type' => 'purchase for download', 'url' => 'https://www.qobuz.com/gb-en/interpreter/little-simz/123'],
                    ['type' => 'streaming music', 'url' => 'https://open.spotify.com/artist/6eXZu6O7nAUA5z6vLV8NKI?utm_source=fixture'],
                    ['type' => 'streaming music', 'url' => 'https://open.spotify.com/artist/6eXZu6O7nAUA5z6vLV8NKI/'],
                    ['type' => 'official homepage', 'url' => 'http://unsafe.example.test'],
                ],
                'enriched_at' => now(),
            ],
        );
        EntityNarrative::query()->create([
            'entity_id' => $artistId,
            'provider_slug' => 'wikipedia',
            'kind' => 'description',
            'language' => 'en',
            'status' => 'ready',
            'body' => 'A lower-priority cached Wikipedia biography.',
            'source_url' => 'https://en.wikipedia.org/wiki/Little_Simz',
            'external_id' => 'Little Simz',
            'content_sha256' => hash('sha256', 'A lower-priority cached Wikipedia biography.'),
            'license_name' => 'CC BY-SA 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
            'fetched_at' => now(),
        ]);
        $this->getJson("/api/v1/artists/{$artistId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Little Simz')
            ->assertJsonPath('data.description.provider', 'theaudiodb')
            ->assertJsonPath('data.description.provider_name', 'TheAudioDB')
            ->assertJsonPath('data.description.language', 'en')
            ->assertJsonPath('data.description.text', 'An attributed biography of an owned artist.')
            ->assertJsonPath('data.description.source_url', 'https://www.theaudiodb.com/artist/67890')
            ->assertJsonPath('data.description.license_name', 'TheAudioDB terms of use')
            ->assertJsonPath('data.description.license_url', 'https://www.theaudiodb.com/docs_terms_of_use.php')
            ->assertJsonCount(4, 'data.external_links.primary')
            ->assertJsonPath('data.external_links.primary.0.label', 'Official site')
            ->assertJsonPath('data.external_links.primary.1.label', 'MusicBrainz')
            ->assertJsonPath('data.external_links.primary.2.label', 'Wikipedia')
            ->assertJsonPath('data.external_links.primary.3.label', 'Discogs')
            ->assertJsonPath('data.external_links.groups.0.label', 'Official and stores')
            ->assertJsonCount(2, 'data.external_links.groups.0.links')
            ->assertJsonPath('data.external_links.groups.0.links.0.url', 'https://littlesimz.bandcamp.com')
            ->assertJsonPath('data.external_links.groups.0.links.1.url', 'https://qobuz.com/gb-en/interpreter/little-simz/123')
            ->assertJsonPath('data.qobuz.status', 'exact')
            ->assertJsonPath('data.qobuz.url', 'https://qobuz.com/gb-en/interpreter/little-simz/123')
            ->assertJsonPath('data.external_links.groups.1.label', 'Listen')
            ->assertJsonCount(1, 'data.external_links.groups.1.links')
            ->assertJsonPath('data.external_links.groups.1.links.0.url', 'https://open.spotify.com/artist/6eXZu6O7nAUA5z6vLV8NKI')
            ->assertJsonStructure(['data' => ['relationships' => ['status', 'roles', 'people', 'works']]]);
        DB::table('catalog.entity_narratives')
            ->where('entity_id', $artistId)
            ->update(['status' => 'stale']);
        $this->getJson("/api/v1/artists/{$artistId}")
            ->assertOk()
            ->assertJsonPath('data.description', null);
        $this->getJson('/api/v1/metadata/coverage')
            ->assertOk()
            ->assertJsonPath('data.entities.1.type', 'album')
            ->assertJsonPath('data.entities.1.identified', 1)
            ->assertJsonPath('data.entities.1.missing_identity', 0)
            ->assertJsonPath('data.entities.1.artwork_ready', 1);
        $home = $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.collection.albums', 1)
            ->assertJsonPath('data.feature.album.title', 'Sometimes I Might Be Introvert')
            ->assertJsonPath('data.feature.reasons.0.source', 'plex')
            ->assertJsonMissingPath('data.feature.rank_score')
            ->assertJsonMissingPath('data.feature.module_type');
        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('meta.edition_id', $home->json('meta.edition_id'));
        $this->assertSame(1, DB::table('discovery.home_editions')->count());
        $this->assertSame(1, DB::table('discovery.recommendation_runs')->count());
        $this->assertSame(1, DB::table('discovery.recommendation_items')->count());
        $this->assertSame(2, DB::table('discovery.recommendation_evidence')->count());
        $recommendationRunId = DB::table('discovery.recommendation_runs')->value('id');
        $editionId = $home->json('meta.edition_id');
        $this->assertSame($recommendationRunId, DB::table('discovery.home_editions')->value('recommendation_run_id'));
        $this->assertSame($owner->id, DB::table('discovery.home_editions')->value('user_id'));
        $this->assertSame('home_edition', DB::table('discovery.recommendation_runs')->value('intent'));
        $this->assertSame('waiting', DB::table('discovery.recommendation_items')->value('module_type'));
        $this->assertSame('owned', data_get(
            json_decode((string) DB::table('discovery.recommendation_items')->value('eligibility'), true, 512, JSON_THROW_ON_ERROR),
            'scope',
        ));
        $this->putJson("/api/v1/home/editions/{$editionId}/recommendations/{$albumId}/feedback", [
            'action' => 'not_for_me',
            'reason' => 'Not today',
        ])->assertOk()
            ->assertJsonPath('data.entity_id', $albumId)
            ->assertJsonPath('data.action', 'not_for_me')
            ->assertJsonPath('data.reason', 'Not today');
        $feedbackId = DB::table('discovery.recommendation_feedback')->value('id');
        $dismissedHome = $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.feature', null)
            ->assertJsonPath('data.collection.albums', 1);
        $this->assertNotSame($editionId, $dismissedHome->json('meta.edition_id'));
        $this->assertSame(2, DB::table('discovery.recommendation_runs')->count());
        $this->assertSame(1, DB::table('discovery.recommendation_items')->count());
        $this->putJson("/api/v1/home/editions/{$editionId}/recommendations/{$albumId}/feedback", [
            'action' => 'interested',
        ])->assertOk()
            ->assertJsonPath('data.id', $feedbackId)
            ->assertJsonPath('data.action', 'interested');
        $this->assertSame(1, DB::table('discovery.recommendation_feedback')->count());
        $restoredHome = $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.feature.album.id', $albumId);
        $this->assertNotSame($dismissedHome->json('meta.edition_id'), $restoredHome->json('meta.edition_id'));
        $this->putJson("/api/v1/home/editions/{$editionId}/recommendations/{$albumId}/feedback", [
            'action' => 'unsupported',
        ])->assertUnprocessable();
        $this->deleteJson("/api/v1/home/editions/{$editionId}/recommendations/00000000-0000-4000-8000-000000000000/feedback")->assertNotFound();
        $this->deleteJson("/api/v1/recommendation-feedback/{$albumId}")->assertNoContent();
        $this->assertSame(0, DB::table('discovery.recommendation_feedback')->count());
        $afterDeleteHome = $this->getJson('/api/v1/home')->assertOk();
        $this->assertNotSame($restoredHome->json('meta.edition_id'), $afterDeleteHome->json('meta.edition_id'));
        DB::table('discovery.home_editions')->where('id', $editionId)->update(['generated_at' => now()->subDays(200)]);
        DB::table('discovery.recommendation_runs')->where('id', $recommendationRunId)->update(['generated_at' => now()->subDays(200)]);
        Artisan::call('disco:recommendation-prune', ['--days' => 30]);
        $this->assertFalse(DB::table('discovery.home_editions')->where('id', $editionId)->exists());
        $this->assertFalse(DB::table('discovery.recommendation_runs')->where('id', $recommendationRunId)->exists());
        $this->assertTrue(DB::table('discovery.home_editions')->where('id', $afterDeleteHome->json('meta.edition_id'))->exists());
        $target = $this->getJson("/api/v1/plex/open-target/{$plexItemId}")->assertOk();
        $this->assertStringNotContainsString('fixture-token', $target->json('url'));

        $replacementMbid = '44444444-4444-4444-8444-444444444444';
        $albumPayload = str_replace('22222222-2222-4222-8222-222222222222', $replacementMbid, $albumPayload);
        app(PlexSyncService::class)->sync();
        $newAlbumId = DB::table('library.plex_entity_matches')
            ->where('match_scope', 'release_group')
            ->where('status', 'confirmed')
            ->value('entity_id');
        $newReleaseId = DB::table('library.plex_entity_matches')
            ->where('plex_item_id', $plexItemId)
            ->where('match_scope', 'release')
            ->where('status', 'confirmed')
            ->value('entity_id');
        $this->assertSame($albumId, $newAlbumId);
        $this->assertNotSame($releaseId, $newReleaseId);
        $this->assertSame(1, DB::table('library.plex_entity_matches')
            ->where('plex_item_id', $plexItemId)
            ->where('match_scope', 'release_group')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->count());
        $this->assertSame($albumId, DB::table('library.holdings')->value('release_group_id'));
        $this->assertSame($newReleaseId, DB::table('library.holdings')->value('release_id'));
        $this->assertSame(5, DB::table('catalog.entities')->count());
        $this->assertSame(2, DB::table('catalog.releases')->count());
        $this->getJson("/api/v1/albums/{$newAlbumId}")->assertOk();
        $this->getJson("/api/v1/albums/{$albumId}")
            ->assertOk()
            ->assertJsonPath('data.id', $albumId)
            ->assertJsonPath('data.basis_release_id', $newReleaseId);

        DB::table('library.plex_entity_matches')
            ->where('plex_item_id', $plexItemId)
            ->where('entity_id', $newReleaseId)
            ->where('match_scope', 'release')
            ->update(['method' => 'manual']);
        $manualMbid = '66666666-6666-4666-8666-666666666666';
        $albumPayload = str_replace($replacementMbid, $manualMbid, $albumPayload);
        app(PlexSyncService::class)->sync();
        $this->assertSame($newReleaseId, DB::table('library.holdings')->value('release_id'));
        $this->assertSame(5, DB::table('catalog.entities')->count());
        $this->assertSame('release_parent', DB::table('library.plex_entity_matches')
            ->where('plex_item_id', $plexItemId)
            ->where('match_scope', 'release_group')
            ->where('status', 'confirmed')
            ->value('method'));
        $this->assertFalse(DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release')
            ->where('value', $manualMbid)
            ->exists());

        $this->postJson('/auth/logout')->assertNoContent();
        $this->getJson($artworkUrl)->assertUnauthorized();
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/plex/{$name}"));
    }

    private function imageFixture(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}
