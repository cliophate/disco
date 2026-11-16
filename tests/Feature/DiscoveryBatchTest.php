<?php

namespace Tests\Feature;

use App\Models\DiscoveryBatch;
use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryPick;
use App\Music\Discovery\DiscoveryAlbumResolver;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\Discovery\PickStateReconciler;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\DiscoveryTestCase;

class DiscoveryBatchTest extends DiscoveryTestCase
{
    private function providers(): array
    {
        $lb = Mockery::mock(ListenBrainzClient::class);
        $lb->shouldReceive('configured')->andReturnTrue();
        $mb = Mockery::mock(MusicBrainzClient::class);
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('prepare')->andReturnUsing(fn ($group, $mbid, $name, $release = null) => [
            'artist_mbid' => $mbid, 'artist_name' => $name, 'release_group_mbid' => $group['id'],
            'title' => $group['title'], 'release_type' => $group['primary-type'] ?? 'Album', 'release_date' => '2026-11-01',
            'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        $this->app->instance(ListenBrainzClient::class, $lb);
        $this->app->instance(MusicBrainzClient::class, $mb);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);

        return [$lb, $mb, $albums];
    }

    public function test_multiple_seeds_rank_higher_and_all_credited_seeds_obey_the_cap(): void
    {
        [$lb, , $albums] = $this->providers();
        $event = $this->listen('Seed One & Seed Two', $this->mbid(1));
        DB::table('activity.listen_identities')->where('listening_event_id', $event->id)->update([
            'artist_mbids' => json_encode([$this->mbid(1), $this->mbid(2)]),
            'artist_names' => json_encode([$this->mbid(1) => 'Seed One', $this->mbid(2) => 'Seed Two']),
        ]);
        $lb->shouldReceive('artistRadio')->once()->with($this->mbid(1))->andReturn([
            $this->mbid(10) => 'Shared', $this->mbid(11) => 'One A', $this->mbid(12) => 'One B', $this->mbid(13) => 'One C',
        ]);
        $lb->shouldReceive('artistRadio')->once()->with($this->mbid(2))->andReturn([
            $this->mbid(10) => 'Shared', $this->mbid(14) => 'Two A', $this->mbid(15) => 'Two B',
        ]);
        $lb->shouldReceive('artistPopularity')->once()->andReturn([]);
        $lb->shouldReceive('topReleaseGroups')->andReturnUsing(fn ($id) => [['release_group_mbid' => $this->mbid((int) substr($id, 0, 8) + 100), 'total_listen_count' => 100]]);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id, $artist) => ['id' => $id, 'title' => 'Album '.$id, 'primary-type' => 'Album']);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $picks = DiscoveryPick::query()->where('batch_id', $batch->id)->orderBy('position')->get();
        $this->assertCount(3, $picks);
        $this->assertSame('Shared', $picks[0]->artist_name);
        $this->assertSame('Similar to Seed One and Seed Two', $picks[0]->reason);
        $this->assertCount(3, $picks->unique('artist_mbid'));
        $credits = $picks->flatMap(fn ($pick) => $pick->seeds)->groupBy('mbid');
        foreach ($credits as $rows) {
            $this->assertLessThanOrEqual(2, $rows->count());
        }
        $this->assertSame(6, DiscoveryCandidate::query()->count());
        $this->assertSame($batch->id, app(DiscoveryBatchGenerator::class)->generate('new_artists')->id);
        $this->assertSame(3, DiscoveryPick::query()->count());
    }

    public function test_popularity_penalty_uses_artist_listeners(): void
    {
        config()->set('discovery.max_listener_count', 2000000);
        [$lb, , $albums] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(10) => 'Popular', $this->mbid(11) => 'Small']);
        $lb->shouldReceive('artistPopularity')->andReturn([$this->mbid(10) => 1000000, $this->mbid(11) => 100]);
        $lb->shouldReceive('topReleaseGroups')->andReturnUsing(fn ($id) => [['release_group_mbid' => $this->mbid((int) substr($id, 0, 8) + 100)]]);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => 'Album']);
        app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame('Small', DiscoveryPick::query()->orderBy('position')->first()->artist_name);
        $this->assertEqualsWithDelta(log1p(3.1) / sqrt(201), DiscoveryCandidate::query()->find($this->mbid(10))->score, 0.00001);
        $this->assertLessThan(DiscoveryCandidate::query()->find($this->mbid(11))->score, DiscoveryCandidate::query()->find($this->mbid(10))->score);
    }

    public function test_popularity_failure_keeps_radio_candidates_and_records_degradation(): void
    {
        [$lb] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(10) => 'Candidate']);
        $lb->shouldReceive('artistPopularity')->andThrow(new RuntimeException('unavailable'));
        $lb->shouldReceive('topReleaseGroups')->andReturn([]);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame('completed', $batch->status);
        $this->assertFalse($batch->summary['popularity_available']);
        $this->assertSame(1, $batch->summary['provider_failures']);
        $this->assertSame(['listenbrainz.artist_popularity:RuntimeException' => 1], $batch->summary['provider_failure_categories']);
        $this->assertSame(1, DiscoveryCandidate::query()->count());
    }

    public function test_all_radio_failures_leave_the_last_successful_pool_intact(): void
    {
        [$lb] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $batch = $this->batch();
        DiscoveryCandidate::query()->create(['artist_mbid' => $this->mbid(10), 'artist_name' => 'Saved', 'score' => 1, 'seeds' => [], 'batch_id' => $batch->id]);
        $lb->shouldReceive('artistRadio')->andThrow(new RuntimeException('unavailable'));
        try {
            app(DiscoveryBatchGenerator::class)->generate('new_artists');
            $this->fail('Every seed failed.');
        } catch (RuntimeException) {
            $this->assertSame(1, DiscoveryCandidate::query()->count());
            $this->assertSame('failed', DiscoveryBatch::query()->latest('period')->first()->status);
        }
    }

    public function test_more_uses_high_taste_artists_and_skips_owned_and_heard_albums(): void
    {
        [, $mb, $albums] = $this->providers();
        $this->listen('Seed', $this->mbid(1), album: 'Heard', groupMbid: $this->mbid(102));
        $this->plex('Seed', '101');
        $this->plex('Owned', '201', 'album', '101');
        $mb->shouldReceive('artistReleaseGroups')->with($this->mbid(1))->andReturn(['release_groups' => [
            ...array_map(fn ($id) => ['id' => $this->mbid($id), 'primary-type' => 'Album', 'artist-credit' => [['artist' => ['id' => $this->mbid(1)]]]], [101, 102, 103]),
        ]]);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => match ($id) {
            $this->mbid(101) => 'Owned', $this->mbid(102) => 'Heard', default => 'Unheard',
        }]);
        app(DiscoveryBatchGenerator::class)->generate('more');
        $this->assertSame(['Unheard'], DiscoveryPick::query()->pluck('title')->all());
    }

    public function test_popularity_cap_boundary_and_unknown_popularity_are_configurable(): void
    {
        config()->set('discovery.max_listener_count', 1000);
        config()->set('discovery.popular_listener_threshold', 100);
        [$lb] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(10) => 'Over cap', $this->mbid(11) => 'At cap', $this->mbid(12) => 'Unknown']);
        $lb->shouldReceive('artistPopularity')->andReturn([$this->mbid(10) => 1001, $this->mbid(11) => 1000]);
        $lb->shouldReceive('topReleaseGroups')->andReturn([]);
        app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertNull(DiscoveryCandidate::query()->find($this->mbid(10)));
        $this->assertEqualsWithDelta(log1p(3.1) / sqrt(11), DiscoveryCandidate::query()->find($this->mbid(11))->score, 0.00001);
        $this->assertEqualsWithDelta(log1p(3.1), DiscoveryCandidate::query()->find($this->mbid(12))->score, 0.00001);
    }

    public function test_new_artists_skip_heard_groups_and_normalized_album_names(): void
    {
        [$lb, , $albums] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $this->listen('Candidate', $this->mbid(10), album: 'Heard by ID', groupMbid: $this->mbid(101));
        $this->listen(' CANDIDATE ', album: ' heard by name ');
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(10) => 'Candidate']);
        $lb->shouldReceive('artistPopularity')->andReturn([]);
        $lb->shouldReceive('topReleaseGroups')->andReturn(array_map(fn ($id) => ['release_group_mbid' => $this->mbid($id)], [101, 102, 103]));
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => match ($id) {
            $this->mbid(101) => 'Renamed', $this->mbid(102) => 'Heard by name', default => 'Unheard',
        }]);
        app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame(['Unheard'], DiscoveryPick::query()->pluck('title')->all());
        $this->assertSame('new', DiscoveryPick::query()->first()->state);
    }

    public function test_section_locks_are_independent_and_reconcile_runs_after_unlocking(): void
    {
        $this->travelBack();
        [$lb] = $this->providers();
        $lb->shouldReceive('userFreshReleases')->andReturn(['payload' => ['releases' => []]]);
        $other = Cache::lock('disco:discovery-generation:new_artists', 3600);
        $this->assertTrue($other->get());
        $reconciler = Mockery::mock(PickStateReconciler::class);
        $reconciler->shouldReceive('reconcile')->once()->andReturnUsing(function () {
            $lock = Cache::lock('disco:discovery-generation:new_releases', 3600);
            $this->assertTrue($lock->get());
            $lock->release();

            return 0;
        });
        $this->app->instance(PickStateReconciler::class, $reconciler);
        try {
            $this->assertSame('completed', app(DiscoveryBatchGenerator::class)->generate('new_releases')->status);
            $this->expectException(LockTimeoutException::class);
            app(DiscoveryBatchGenerator::class)->generate('new_artists');
        } finally {
            $other->release();
        }
    }

    public function test_batch_failure_categories_group_endpoint_status_and_exception_class(): void
    {
        [$lb] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(10) => 'One', $this->mbid(11) => 'Two', $this->mbid(12) => 'Three']);
        $lb->shouldReceive('artistPopularity')->andThrow(new RuntimeException('Do not persist this payload'));
        $lb->shouldReceive('topReleaseGroups')->andReturnUsing(fn () => throw new RequestException(new Response(new \GuzzleHttp\Psr7\Response(404))));
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame(4, $batch->summary['provider_failures']);
        $this->assertEquals(['listenbrainz.artist_popularity:RuntimeException' => 1, 'listenbrainz.top_release_groups:404' => 3], $batch->summary['provider_failure_categories']);
        $this->assertStringNotContainsString('Do not persist', json_encode($batch->summary));
    }

    public function test_more_uses_individual_artist_names_and_deduplicates_normalized_names(): void
    {
        [, $mb, $albums] = $this->providers();
        $event = $this->listen('Artist One & Artist Two', $this->mbid(1));
        DB::table('activity.listen_identities')->where('listening_event_id', $event->id)->update([
            'artist_mbids' => json_encode([$this->mbid(1), $this->mbid(2)]),
            'artist_names' => json_encode([$this->mbid(1) => 'Artist One', $this->mbid(2) => 'Artist Two']),
        ]);
        $this->listen(' ARTIST ONE ', $this->mbid(3));
        $mb->shouldReceive('artistReleaseGroups')->twice()->andReturnUsing(fn ($id) => ['release_groups' => [[
            'id' => $this->mbid(100 + (int) substr($id, 0, 8)), 'title' => 'Album', 'primary-type' => 'Album',
            'artist-credit' => [['artist' => ['id' => $id]]],
        ]]]);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => 'Album']);
        app(DiscoveryBatchGenerator::class)->generate('more');
        $picks = DiscoveryPick::query()->orderBy('position')->get();
        $this->assertSame(['Artist One', 'Artist Two'], $picks->pluck('artist_name')->all());
        $this->assertSame(['An unheard album by Artist One', 'An unheard album by Artist Two'], $picks->pluck('reason')->all());
    }
}
