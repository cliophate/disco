<?php

namespace Tests\Feature;

use App\Jobs\RefreshArtistDiscography;
use App\Models\Agent;
use App\Models\AlbumListItem;
use App\Models\ArtistDiscographyGeneration;
use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\EntityMetadata;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlayAggregate;
use App\Models\PlexItem;
use App\Models\PlexItemArtwork;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\UpcomingReleaseGeneration;
use App\Models\UpcomingReleaseItem;
use App\Models\User;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Discovery\ArtistDiscographyArtworkEnricher;
use App\Music\Discovery\ArtistDiscographyRefresher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ArtistDiscographyTest extends TestCase
{
    public function test_discography_is_exact_official_canonical_stateful_and_provider_free(): void
    {
        $this->preparePostgres();
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        config()->set('discovery.discography.page_size', 2);
        config()->set('discovery.discography.max_pages', 3);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $artistMbid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $artist = $this->artist('Fixture Artist', $artistMbid);
        $this->fakeDiscography($artistMbid);

        $result = app(ArtistDiscographyRefresher::class)->refresh($artist->id);

        $this->assertSame(4, $result['items']);
        $this->assertSame(3, $result['pages']);
        $this->assertFalse($result['truncated']);
        $this->assertDatabaseCount('discovery.artist_discography_items', 4);
        $this->assertDatabaseCount('catalog.releases', 4);
        $artwork = Mockery::mock(CoverArtArchiveIngestor::class);
        $artwork->shouldReceive('ingest')->times(4)->andReturnUsing(function (CatalogEntity $entity): CatalogEntityArtwork {
            return CatalogEntityArtwork::query()->updateOrCreate(['entity_id' => $entity->id], [
                'status' => 'ready',
                'content_sha256' => hash('sha256', $entity->id),
                'storage_key' => "fixture/{$entity->id}.webp",
            ]);
        });
        $discographyArtwork = new ArtistDiscographyArtworkEnricher($artwork);
        $this->assertSame(2, $discographyArtwork->enrich(2, $artist->id)['ready']);
        $this->assertSame(2, $discographyArtwork->enrich(2, $artist->id)['ready']);
        $this->assertSame(0, $discographyArtwork->enrich(2, $artist->id)['requested']);
        $this->assertDatabaseMissing('discovery.artist_discography_items', ['release_group_mbid' => $this->mbid(4, '10000000')]);
        $this->assertDatabaseMissing('discovery.artist_discography_items', ['release_group_mbid' => $this->mbid(5, '10000000')]);
        $this->assertDatabaseHas('catalog.releases', [
            'entity_id' => ExternalIdentifier::query()->where('namespace', 'musicbrainz.release')->where('value', $this->mbid(1, '20000000'))->value('entity_id'),
            'release_year' => 2000,
        ]);
        $epRelease = ExternalIdentifier::query()->where('namespace', 'musicbrainz.release')->where('value', $this->mbid(3, '20000000'))->firstOrFail();
        $this->assertDatabaseHas('catalog.releases', ['entity_id' => $epRelease->entity_id, 'release_year' => null]);

        $owned = $this->group($this->mbid(1, '10000000'));
        $ep = $this->group($this->mbid(3, '10000000'));
        $this->holding($owned, false, 2);
        $this->holding($ep, true, 8);
        AlbumListItem::query()->create([
            'user_id' => $user->id, 'release_group_entity_id' => $ep->id, 'status' => 'want_to_listen',
            'wanted_at' => now(), 'state_changed_at' => now(),
        ]);
        PlayAggregate::query()->create(['release_group_entity_id' => $ep->id, 'play_count' => 3, 'first_listened_at' => now()->subDay(), 'last_listened_at' => now()]);
        $this->recommend($user, $ep);
        $this->upcoming($ep);

        $canonicalEp = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Canonical EP', 'sort_name' => 'Canonical EP']);
        ReleaseGroup::query()->create(['entity_id' => $canonicalEp->id, 'primary_type' => 'ep', 'secondary_types' => []]);
        EntityMetadata::query()->create([
            'entity_id' => $canonicalEp->id, 'source_provider' => 'musicbrainz', 'genres' => [], 'primary_type' => 'ep',
            'first_release_year' => 2002, 'first_release_precision' => 'year', 'artist_credit' => [[
                'name' => 'Fixture Artist', 'artist_mbid' => $artistMbid, 'artist_entity_id' => $artist->id, 'joinphrase' => '',
            ]], 'external_links' => [], 'attributes' => [], 'enriched_at' => now(),
        ]);
        $ep->update(['status' => 'redirected', 'redirect_entity_id' => $canonicalEp->id]);
        $canonicalArtist = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => 'Canonical Artist', 'sort_name' => 'Canonical Artist']);
        Agent::query()->create(['entity_id' => $canonicalArtist->id, 'agent_type' => 'group']);
        $artist->update(['status' => 'redirected', 'redirect_entity_id' => $canonicalArtist->id]);
        ExternalIdentifier::query()->where('namespace', 'musicbrainz.artist')->where('value', $artistMbid)->update(['entity_id' => $canonicalArtist->id]);

        $this->actingAs($user);
        $providerCalls = count(Http::recorded());
        $this->getJson("/api/v1/artists/{$artist->id}/discography")
            ->assertOk()
            ->assertJsonPath('meta.status', 'ready')
            ->assertJsonPath('meta.view', 'missing')
            ->assertJsonPath('meta.types', 'albums')
            ->assertJsonPath('meta.noise', 'core')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.album.title', 'Missing Core Album')
            ->assertJsonPath('data.0.states.holding', 'absent')
            ->assertJsonPath('data.0.official_release_evidence.status', 'official');

        $all = $this->getJson("/api/v1/artists/{$artist->id}/discography?view=all&types=all&noise=all&page[size]=2")
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.counts.views.present', 1)
            ->assertJsonPath('meta.counts.types.albums', 3)
            ->assertJsonPath('meta.counts.types.albums_eps', 4)
            ->assertJsonPath('data.0.album.artwork.width', 800)
            ->assertJsonCount(2, 'data');
        $this->assertStringContainsString('generation_id='.$result['generation_id'], $all->json('links.next'));
        $second = $this->getJson("/api/v1/artists/{$artist->id}/discography?view=all&types=all&noise=all&page[number]=2&page[size]=2&generation_id={$result['generation_id']}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $canonicalEp->id)
            ->assertJsonPath('data.0.states.holding', 'absent')
            ->assertJsonPath('data.0.states.wanted', true)
            ->assertJsonPath('data.0.states.listened', false)
            ->assertJsonPath('data.0.states.recommended', true)
            ->assertJsonPath('data.0.states.upcoming', true)
            ->assertJsonPath('data.0.states.observed_listening', true)
            ->assertJsonPath('data.0.album.list_state.status', 'want_to_listen');
        $this->assertCount($providerCalls, Http::recorded(), 'Discography reads must not contact MusicBrainz or another provider.');

        Queue::fake();
        ArtistDiscographyGeneration::query()->findOrFail($result['generation_id'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/artists/{$artist->id}/discography")
            ->assertOk()
            ->assertJsonPath('meta.status', 'stale')
            ->assertJsonPath('meta.refresh.status', 'queued');
        Queue::assertPushed(RefreshArtistDiscography::class, 1);
        $this->postJson("/api/v1/artists/{$artist->id}/discography/refresh")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RefreshArtistDiscography::class, 1);

        $conflict = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => 'Conflict', 'sort_name' => 'Conflict']);
        Agent::query()->create(['entity_id' => $conflict->id, 'agent_type' => 'group']);
        ExternalIdentifier::query()->create(['entity_id' => $conflict->id, 'namespace' => 'musicbrainz.release_group', 'value' => $this->mbid(7, '10000000'), 'status' => 'active']);
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake(['*' => Http::response(['release-group-count' => 1, 'release-groups' => [
            $this->releaseGroup(7, 'Conflicting Album', 'Album', [], $artistMbid, true, '2005', false),
        ]], 200, ['Content-Type' => 'application/json'])]);
        $failed = false;
        try {
            app(ArtistDiscographyRefresher::class)->refresh($artist->id);
        } catch (RuntimeException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'Invalid refresh should fail.');
        $this->assertDatabaseCount('discovery.artist_discography_generations', 1);
        $this->assertNotNull(ArtistDiscographyGeneration::query()->find($result['generation_id']));
    }

    public function test_discography_endpoint_is_authenticated_and_refresh_is_bounded(): void
    {
        $this->getJson('/api/v1/artists/11111111-1111-4111-8111-111111111111/discography')->assertUnauthorized();
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:artist-discographies --limit=2'));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $artworkEvent = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:catalog-enrich --limit=50'));
        $this->assertInstanceOf(Event::class, $artworkEvent);
        $this->assertSame('*/10 * * * *', $artworkEvent->expression);
        $this->assertTrue($artworkEvent->withoutOverlapping);
        $this->assertTrue($artworkEvent->onOneServer);
        $this->assertFalse(collect($this->app->make(Schedule::class)->events())
            ->contains(fn (Event $event): bool => str_contains($event->command, 'disco:discography-artwork --limit=15')));
    }

    private function fakeDiscography(string $artistMbid): void
    {
        $groups = [
            $this->releaseGroup(1, 'Owned Core Album', 'Album', [], $artistMbid, true, '2000-01-02', true),
            $this->releaseGroup(2, 'Live Album', 'Album', ['Live'], $artistMbid, true, '2001', false),
            $this->releaseGroup(3, 'Collaborative EP', 'EP', [], $artistMbid, false, '2002', false, true),
            $this->releaseGroup(4, 'Various Artists Intrusion', 'Album', [], 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', true, '2003', false),
            $this->releaseGroup(5, 'Unofficial Album', 'Album', [], $artistMbid, false, '2003', false),
            $this->releaseGroup(6, 'Missing Core Album', 'Album', [], $artistMbid, true, '2004', false),
        ];
        Http::fake(function (Request $request) use ($groups) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with((string) $path, '/release-group')) {
                $offset = (int) ($query['offset'] ?? 0);

                return Http::response([
                    'release-group-count' => count($groups),
                    'release-group-offset' => $offset,
                    'release-groups' => array_slice($groups, $offset, 2),
                ], 200, ['Content-Type' => 'application/json']);
            }
            $groupMbid = (string) ($query['release-group'] ?? '');
            $groupNumber = str_ends_with($groupMbid, '000000000003') ? 3 : 5;
            $status = $groupNumber === 3 ? 'Official' : 'Promotion';

            return Http::response(['release-count' => 1, 'releases' => [[
                'id' => $this->mbid($groupNumber, '20000000'),
                'status' => $status,
                'release-group' => ['id' => $groupMbid],
            ]]], 200, ['Content-Type' => 'application/json']);
        });
    }

    /** @param list<string> $secondary
     * @return array<string,mixed>
     */
    private function releaseGroup(int $number, string $title, string $type, array $secondary, string $artistMbid, bool $official, string $date, bool $multipleEditions, bool $collaboration = false): array
    {
        $credits = [[
            'name' => $number === 4 ? 'Various Artists' : 'Fixture Artist',
            'artist' => ['id' => $artistMbid, 'name' => $number === 4 ? 'Various Artists' : 'Fixture Artist'],
            'joinphrase' => $collaboration ? ' & ' : '',
        ]];
        if ($collaboration) {
            $credits[] = ['name' => 'Collaborator', 'artist' => ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'name' => 'Collaborator'], 'joinphrase' => ''];
        }
        $releases = [[
            'id' => $this->mbid($number, '20000000'),
            'status' => $official ? 'Official' : 'Promotion',
            ...($number === 1 ? ['date' => '2000-06-07'] : []),
        ]];
        if ($multipleEditions) {
            $releases[] = ['id' => $this->mbid(101, '20000000'), 'status' => 'Official', 'date' => '2001-01-01'];
        }

        return [
            'id' => $this->mbid($number, '10000000'),
            'title' => $title,
            'primary-type' => $type,
            'secondary-types' => $secondary,
            'first-release-date' => $date,
            'artist-credit' => $credits,
            'releases' => $releases,
        ];
    }

    private function artist(string $name, string $mbid): CatalogEntity
    {
        $entity = CatalogEntity::query()->create(['kind' => 'agent', 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        Agent::query()->create(['entity_id' => $entity->id, 'agent_type' => 'group']);
        ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => 'musicbrainz.artist', 'value' => $mbid, 'status' => 'active']);

        return $entity;
    }

    private function group(string $mbid): CatalogEntity
    {
        return ExternalIdentifier::query()->where('namespace', 'musicbrainz.release_group')->where('value', $mbid)->firstOrFail()->entity;
    }

    private function holding(CatalogEntity $group, bool $removed, int $views): void
    {
        $server = PlexServer::query()->firstOrCreate(['machine_identifier' => 'machine'], ['name' => 'Plex', 'machine_identifier_hash' => hash('sha256', 'machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->firstOrCreate(['plex_server_id' => $server->id, 'section_key' => '1'], ['section_uuid' => 'library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id, 'rating_key' => $group->id, 'item_type' => 'album', 'title' => $group->canonical_name,
            'sort_title' => $group->canonical_name, 'view_count' => $views, 'raw_metadata' => [], 'last_synced_at' => now(),
            'removed_at' => $removed ? now() : null,
        ]);
        if (! $removed) {
            PlexItemArtwork::query()->create([
                'plex_item_id' => $item->id,
                'status' => 'ready',
                'content_sha256' => hash('sha256', $item->id),
                'storage_key' => "fixture/{$item->id}.webp",
                'mime_type' => 'image/webp',
                'size_bytes' => 100,
                'width' => 800,
                'height' => 800,
                'attempt_count' => 1,
                'ingested_at' => now(),
            ]);
        }
        Holding::query()->create(['release_group_id' => $group->id, 'plex_album_item_id' => $item->id, 'ownership_type' => 'digital', 'is_primary_playback_copy' => true]);
    }

    private function recommend(User $user, CatalogEntity $group): void
    {
        $run = RecommendationRun::query()->create([
            'user_id' => $user->id, 'intent' => 'beyond_library', 'input' => [], 'algorithm_version' => 'test',
            'configuration_hash' => hash('sha256', 'test'), 'random_seed' => 1, 'catalog_version' => 'test',
            'status' => 'completed', 'generated_at' => now(),
        ]);
        RecommendationItem::query()->create([
            'run_id' => $run->id, 'entity_id' => $group->id, 'rank' => 1, 'score' => 1,
            'component_scores' => [], 'eligibility' => [], 'module_type' => 'beyond-library',
            'explanation_text' => 'Fixture', 'explanation_version' => 'test',
        ]);
    }

    private function upcoming(CatalogEntity $group): void
    {
        $provider = SourceProvider::query()->create(['slug' => 'fixture', 'display_name' => 'Fixture', 'enabled' => true, 'policy' => []]);
        $object = SourceObject::query()->create(['provider_id' => $provider->id, 'object_type' => 'fixture', 'external_id' => 'fixture', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $snapshot = SourceSnapshot::query()->create(['source_object_id' => $object->id, 'retrieved_at' => now(), 'http_status' => 200, 'payload_hash' => hash('sha256', 'fixture'), 'payload' => [], 'parser_version' => 'test']);
        $generation = UpcomingReleaseGeneration::query()->create([
            'source_snapshot_id' => $snapshot->id, 'algorithm_version' => 'test', 'horizon_days' => 30,
            'horizon_reason' => 'Fixture', 'coverage' => [], 'generated_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        UpcomingReleaseItem::query()->create([
            'generation_id' => $generation->id, 'release_group_id' => $group->id,
            'release_group_mbid' => $this->mbid(3, '10000000'), 'release_mbid' => $this->mbid(3, '20000000'),
            'title' => 'Collaborative EP', 'artist_credit_name' => 'Fixture Artist & Collaborator',
            'artist_mbids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
            'release_date' => now()->addDays(10)->toDateString(), 'primary_type' => 'EP', 'secondary_types' => [],
            'artwork_status' => 'unavailable', 'listen_count' => 0, 'tags' => [], 'general_rank' => 1, 'provenance' => [],
        ]);
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
