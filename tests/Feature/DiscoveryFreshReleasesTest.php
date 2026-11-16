<?php

namespace Tests\Feature;

use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryPick;
use App\Music\Discovery\DiscoveryAlbumResolver;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\ListenBrainz\ListenBrainzClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\DiscoveryTestCase;

class DiscoveryFreshReleasesTest extends DiscoveryTestCase
{
    private function release(int $number, string $type = 'Album', ?string $secondary = null, ?int $artist = null): array
    {
        return [
            'artist_credit_name' => 'Artist '.($artist ?? $number), 'artist_mbids' => [$this->mbid($artist ?? $number)],
            'release_date' => $number % 2 === 0 ? '2026-11-20' : '2026-11-01', 'release_group_mbid' => $this->mbid($number + 100),
            'release_group_primary_type' => $type, 'release_group_secondary_type' => $secondary,
            'release_mbid' => $this->mbid($number + 200), 'release_name' => 'Album '.$number,
            'caa_id' => 123, 'caa_release_mbid' => $this->mbid($number + 200), 'listen_count' => 1, 'release_tags' => null,
        ];
    }

    public function test_personal_and_pool_releases_are_filtered_and_deduplicated_without_a_sitewide_feed(): void
    {
        $batch = $this->batch();
        DiscoveryCandidate::query()->create(['artist_mbid' => $this->mbid(20), 'artist_name' => 'Artist 20', 'score' => 1, 'seeds' => [['mbid' => $this->mbid(30), 'name' => 'Seed One'], ['mbid' => $this->mbid(31), 'name' => 'Seed Two']], 'batch_id' => $batch->id]);
        $this->listen('Artist 1', $this->mbid(1));
        $this->listen('Artist 1', $this->mbid(1));
        $this->listen('Artist 2');
        $this->listen('Artist 8', $this->mbid(8), album: 'Heard group', groupMbid: $this->mbid(108));
        $this->listen(' ARTIST 9 ', album: ' album 9 ');
        $this->plex('Artist 5', '101');
        $this->plex('Album 5', '201', 'album', '101');
        $personal = [$this->release(1), $this->release(2, 'EP'), $this->release(3, 'Single'), $this->release(4, 'Album', 'Live'), $this->release(5), $this->release(6, 'Album', 'Soundtrack'), $this->release(8), $this->release(9)];
        $shared = $this->release(30);
        $shared['artist_mbids'] = [$this->mbid(30), $this->mbid(20)];
        $shared['artist_credit_name'] = 'Artist 30 & Artist 20';
        Http::fake([
            'https://listenbrainz.test/1/user/*' => Http::response(['payload' => ['user_id' => 'fixture-listener', 'releases' => $personal]], 200, ['Content-Type' => 'application/json']),
            'https://listenbrainz.test/1/explore/*' => function ($request) use ($shared) {
                $releases = collect([$this->release(20), $this->release(21), $this->release(1), $shared])
                    ->filter(fn ($release) => ($release['release_date'] < '2026-11-16') === str_contains($request->url(), 'past=true'))->values()->all();

                return Http::response(['payload' => ['total_count' => count($releases), 'releases' => $releases]], 200, ['Content-Type' => 'application/json']);
            },
        ]);
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id, $artist) => ['id' => $id, 'title' => 'Album '.((int) substr($id, 0, 8) - 100), 'artist-credit' => [['artist' => ['name' => 'Artist '.((int) substr($id, 0, 8) - 100)]]]]);
        $albums->shouldReceive('prepare')->andReturnUsing(fn ($group, $artist, $name, $release) => [
            'artist_mbid' => $artist, 'artist_name' => $name, 'title' => $group['title'], 'release_group_mbid' => $group['id'],
            'release_type' => 'Album', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);
        app(DiscoveryBatchGenerator::class)->generate('new_releases');
        foreach ([['true', 'false'], ['false', 'true']] as [$past, $future]) {
            Http::assertSent(fn ($request) => str_contains($request->url(), '/1/explore/fresh-releases/')
                && str_contains($request->url(), "past={$past}") && str_contains($request->url(), "future={$future}"));
        }
        $picks = DiscoveryPick::query()->orderBy('position')->get();
        $this->assertSame(['Album 1', 'Album 2', 'Album 6', 'Album 20', 'Album 30'], $picks->pluck('title')->all());
        $this->assertSame("You've played Artist 1 2 times", $picks[0]->reason);
        $this->assertSame('Similar to Seed One and Seed Two', $picks[3]->reason);
        $this->assertSame('Similar to Seed One and Seed Two', $picks[4]->reason);
        $this->assertSame('Artist 30', $picks[4]->artist_name);
        $this->assertSame("You've played Artist 2 1 time", $picks[1]->reason);
        $this->actingAs($this->owner)->getJson('/api/v1/discovery')->assertJsonPath('data.1.picks.0.upcoming', false)->assertJsonPath('data.1.picks.2.upcoming', true);
    }

    public function test_personal_release_payload_is_bound_to_the_configured_user(): void
    {
        Http::fake(['https://listenbrainz.test/*' => Http::response(['payload' => ['user_id' => 'someone-else', 'releases' => []]], 200, ['Content-Type' => 'application/json'])]);
        $this->expectExceptionMessage('invalid personal fresh releases');
        app(ListenBrainzClient::class)->userFreshReleases();
    }

    public function test_daily_runs_add_only_previously_unpicked_releases(): void
    {
        Http::fake(fn () => Http::response(['payload' => ['user_id' => 'fixture-listener', 'releases' => [$this->release(1)]]], 200, ['Content-Type' => 'application/json']));
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('group')->once()->andReturn(['id' => $this->mbid(101), 'title' => 'Album 1']);
        $albums->shouldReceive('prepare')->once()->andReturn([
            'artist_mbid' => $this->mbid(1), 'artist_name' => 'Artist 1', 'title' => 'Album 1', 'release_group_mbid' => $this->mbid(101),
            'release_type' => 'Album', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);
        $this->assertSame(1, app(DiscoveryBatchGenerator::class)->generate('new_releases')->summary['picks']);
        $this->travel(1)->days();
        $this->assertSame(0, app(DiscoveryBatchGenerator::class)->generate('new_releases')->summary['picks']);
        $this->assertSame(1, DiscoveryPick::query()->count());
        $this->actingAs($this->owner)->getJson('/api/v1/discovery')
            ->assertJsonCount(1, 'data.1.picks')->assertJsonPath('data.1.message', null)->assertJsonCount(0, 'data.1.earlier');
    }

    public function test_release_listing_combines_batches_and_orders_released_then_upcoming(): void
    {
        $old = $this->batch('new_releases', '2026-11-15');
        $today = $this->batch('new_releases', '2026-11-16');
        $past = $this->pick($old, ['release_date' => '2026-11-01']);
        $recent = $this->pick($old, ['release_group_mbid' => $this->mbid(21), 'release_date' => '2026-11-16']);
        $soon = $this->pick($old, ['release_group_mbid' => $this->mbid(22), 'release_date' => '2026-11-18']);
        $future = $this->pick($today, ['release_group_mbid' => $this->mbid(23), 'release_date' => '2027-01-01']);
        foreach ([['release_date' => '2026-10-16'], ['state' => 'played'], ['passed_at' => now()], ['release_date' => null]] as $i => $extra) {
            $this->pick($today, ['release_group_mbid' => $this->mbid(24 + $i), ...$extra]);
        }
        $boundary = $this->pick($old, ['release_group_mbid' => $this->mbid(28), 'release_date' => '2026-10-17']);
        $this->actingAs($this->owner)->getJson('/api/v1/discovery')
            ->assertJsonPath('data.1.picks', fn ($picks) => array_column($picks, 'id') === [$recent->id, $past->id, $boundary->id, $soon->id, $future->id])
            ->assertJsonCount(0, 'data.1.earlier');
        Http::assertNothingSent();
    }

    public function test_personal_reasons_count_the_individual_artist_in_a_shared_credit(): void
    {
        $event = $this->listen('Artist One & Collaborator', $this->mbid(1));
        DB::table('activity.listen_identities')->where('listening_event_id', $event->id)->update([
            'artist_mbids' => json_encode([$this->mbid(1), $this->mbid(2)]),
            'artist_names' => json_encode([$this->mbid(1) => 'Artist One', $this->mbid(2) => 'Collaborator']),
        ]);
        $row = $this->release(1);
        $row['artist_credit_name'] = 'Artist One & Collaborator';
        $row['artist_mbids'] = [$this->mbid(1), $this->mbid(2)];
        Http::fake(fn () => Http::response(['payload' => ['user_id' => 'fixture-listener', 'releases' => [$row]]], 200, ['Content-Type' => 'application/json']));
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('group')->andReturn(['id' => $this->mbid(101), 'title' => 'Album 1']);
        $albums->shouldReceive('prepare')->with(Mockery::type('array'), $this->mbid(1), 'Artist One', $this->mbid(201))->andReturn([
            'artist_mbid' => $this->mbid(1), 'artist_name' => 'Artist One', 'title' => 'Album 1', 'release_group_mbid' => $this->mbid(101),
            'release_type' => 'Album', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);
        app(DiscoveryBatchGenerator::class)->generate('new_releases');
        $this->assertSame("You've played Artist One 1 time", DiscoveryPick::query()->first()->reason);
    }
}
