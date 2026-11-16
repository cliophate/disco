<?php

namespace Tests\Feature;

use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryPick;
use App\Music\Discovery\DiscoveryAlbumResolver;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\ListenBrainz\ListenBrainzClient;
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
        DiscoveryCandidate::query()->create(['artist_mbid' => $this->mbid(20), 'artist_name' => 'Artist 20', 'score' => 1, 'seeds' => [], 'batch_id' => $batch->id]);
        $this->plex('Artist 5', '101');
        $this->plex('Album 5', '201', 'album', '101');
        $personal = [$this->release(1), $this->release(2, 'EP'), $this->release(3, 'Single'), $this->release(4, 'Album', 'Live'), $this->release(5), $this->release(6, 'Album', 'Soundtrack')];
        Http::fake([
            'https://listenbrainz.test/1/user/*' => Http::response(['payload' => ['user_id' => 'fixture-listener', 'releases' => $personal]], 200, ['Content-Type' => 'application/json']),
            'https://listenbrainz.test/1/explore/*' => Http::response(['payload' => ['total_count' => 3, 'releases' => [$this->release(20), $this->release(21), $this->release(1)]]], 200, ['Content-Type' => 'application/json']),
        ]);
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id, $artist) => ['id' => $id, 'title' => 'Album '.((int) substr($id, 0, 8) - 100)]);
        $albums->shouldReceive('prepare')->andReturnUsing(fn ($group, $artist, $name, $release) => [
            'artist_mbid' => $artist, 'artist_name' => $name, 'title' => $group['title'], 'release_group_mbid' => $group['id'],
            'release_type' => 'Album', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);
        app(DiscoveryBatchGenerator::class)->generate('new_releases');
        $picks = DiscoveryPick::query()->orderBy('position')->get();
        $this->assertSame(['Album 1', 'Album 2', 'Album 6', 'Album 20'], $picks->pluck('title')->all());
        $this->assertSame('New music from your listening history', $picks[0]->reason);
        $this->assertSame("New music from this week's discoveries", $picks[3]->reason);
        $this->actingAs($this->owner)->getJson('/api/v1/discovery')->assertJsonPath('data.1.picks.0.upcoming', false)->assertJsonPath('data.1.picks.1.upcoming', true);
    }

    public function test_personal_release_payload_is_bound_to_the_configured_user(): void
    {
        Http::fake(['https://listenbrainz.test/*' => Http::response(['payload' => ['user_id' => 'someone-else', 'releases' => []]], 200, ['Content-Type' => 'application/json'])]);
        $this->expectExceptionMessage('invalid personal fresh releases');
        app(ListenBrainzClient::class)->userFreshReleases();
    }
}
