<?php

namespace Tests\Feature;

use App\Models\CatalogEntityArtwork;
use App\Models\DiscoveryPick;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Mockery;
use Tests\DiscoveryTestCase;

class DiscoveryAlbumTest extends DiscoveryTestCase
{
    private function setupAlbums(): array
    {
        $this->listen('Seed', $this->mbid(1));
        $lb = Mockery::mock(ListenBrainzClient::class);
        $lb->shouldReceive('configured')->andReturnTrue();
        $lb->shouldReceive('artistRadio')->andReturn([$this->mbid(2) => 'Candidate']);
        $lb->shouldReceive('artistPopularity')->andReturn([]);
        $mb = Mockery::mock(MusicBrainzClient::class);
        $artwork = Mockery::mock(CoverArtArchiveIngestor::class);
        $this->app->instance(ListenBrainzClient::class, $lb);
        $this->app->instance(MusicBrainzClient::class, $mb);
        $this->app->instance(CoverArtArchiveIngestor::class, $artwork);

        return [$lb, $mb, $artwork];
    }

    public function test_single_only_artist_is_skipped_without_preparing_a_pick(): void
    {
        [$lb, $mb, $artwork] = $this->setupAlbums();
        $lb->shouldReceive('topReleaseGroups')->andReturn([['release_group_mbid' => $this->mbid(20), 'total_listen_count' => 999]]);
        $mb->shouldReceive('entity')->with('release-group', $this->mbid(20))->andReturn(['id' => $this->mbid(20), 'primary-type' => 'Single', 'artist-credit' => [['artist' => ['id' => $this->mbid(2)]]]]);
        $artwork->shouldNotReceive('ingest');
        app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame(0, DiscoveryPick::query()->count());
    }

    public function test_most_listened_eligible_ep_wins_and_release_urls_and_local_artwork_are_prepared(): void
    {
        [$lb, $mb, $artwork] = $this->setupAlbums();
        $lb->shouldReceive('topReleaseGroups')->andReturn([
            ['release_group_mbid' => $this->mbid(21), 'total_listen_count' => 2],
            ['release_group_mbid' => $this->mbid(20), 'total_listen_count' => 999],
            ['release_group_mbid' => $this->mbid(22), 'total_listen_count' => 10],
        ]);
        $mb->shouldReceive('entity')->with('release-group', $this->mbid(20))->andReturn(['id' => $this->mbid(20), 'primary-type' => 'Album', 'secondary-types' => ['Live'], 'artist-credit' => [['artist' => ['id' => $this->mbid(2)]]]]);
        $group = ['id' => $this->mbid(22), 'title' => 'Synthetic EP', 'primary-type' => 'EP', 'secondary-types' => ['Soundtrack'], 'first-release-date' => '2026-01-01', 'artist-credit' => [['artist' => ['id' => $this->mbid(2)]]], 'releases' => [['id' => $this->mbid(32)]]];
        $mb->shouldReceive('entity')->with('release-group', $this->mbid(22))->andReturn($group);
        $mb->shouldReceive('entity')->with('release', $this->mbid(32))->andReturn(['id' => $this->mbid(32), 'title' => 'Synthetic EP', 'release-group' => ['id' => $this->mbid(22)], 'relations' => [['target-type' => 'url', 'url' => ['resource' => 'https://open.qobuz.com/album/synthetic']]]]);
        $mb->shouldNotReceive('entity')->with('release-group', $this->mbid(21));
        $artwork->shouldReceive('ingest')->once()->andReturn(new CatalogEntityArtwork);
        app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $pick = DiscoveryPick::query()->firstOrFail();
        $this->assertSame('EP', $pick->release_type);
        $this->assertSame('https://open.qobuz.com/album/synthetic', $pick->qobuz_url);
        $this->assertSame('exact', $pick->qobuz_status);
        $this->assertNotNull($pick->entity_id);
    }
}
