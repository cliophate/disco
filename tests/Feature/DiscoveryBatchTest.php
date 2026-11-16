<?php

namespace Tests\Feature;

use App\Models\DiscoveryBatch;
use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryPick;
use App\Music\Discovery\DiscoveryAlbumResolver;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\MusicBrainz\MusicBrainzClient;
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
        $this->listen('Seed One', $this->mbid(1));
        $this->listen('Seed Two', $this->mbid(2));
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
        [$lb, , $albums] = $this->providers();
        $this->listen('Seed', $this->mbid(1));
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(10) => 'Popular', $this->mbid(11) => 'Small']);
        $lb->shouldReceive('artistPopularity')->andReturn([$this->mbid(10) => 1000000, $this->mbid(11) => 100]);
        $lb->shouldReceive('topReleaseGroups')->andReturnUsing(fn ($id) => [['release_group_mbid' => $this->mbid((int) substr($id, 0, 8) + 100)]]);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => 'Album']);
        app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame('Small', DiscoveryPick::query()->orderBy('position')->first()->artist_name);
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
            ['id' => $this->mbid(101)], ['id' => $this->mbid(102)], ['id' => $this->mbid(103)],
        ]]);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => match ($id) {
            $this->mbid(101) => 'Owned', $this->mbid(102) => 'Heard', default => 'Unheard',
        }]);
        app(DiscoveryBatchGenerator::class)->generate('more');
        $this->assertSame(['Unheard'], DiscoveryPick::query()->pluck('title')->all());
    }
}
