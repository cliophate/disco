<?php

namespace Tests\Feature;

use App\Music\Discovery\DiscoveryAlbumResolver;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\ListenBrainz\ListenBrainzClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\DiscoveryTestCase;

class DiscoveryMemoryTest extends DiscoveryTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_generation_and_reconcile_fit_in_128m_with_large_synthetic_history(): void
    {
        ini_set('memory_limit', '128M');
        DB::disableQueryLog();
        DB::statement("INSERT INTO catalog.entities (id, kind, status, canonical_name, sort_name, created_at, updated_at)
            SELECT md5('artist-' || n)::uuid, 'agent', 'active', 'Synthetic artist ' || n, 'Synthetic artist ' || n, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM generate_series(1, 20000) n");
        DB::statement("INSERT INTO catalog.external_identifiers (id, entity_id, namespace, value, status, created_at, updated_at)
            SELECT md5('identifier-' || n)::uuid, md5('artist-' || n)::uuid, 'musicbrainz.artist', md5('mbid-' || n)::uuid::text, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM generate_series(1, 20000) n");
        DB::statement("INSERT INTO activity.listening_events (id, source_account_id, source_snapshot_id, fingerprint, listened_at, listened_at_epoch, supplied_artist, supplied_release, supplied_track, created_at)
            SELECT md5('listen-' || n)::uuid, ?::uuid, ?::uuid, md5('fingerprint-' || n), CURRENT_TIMESTAMP, EXTRACT(EPOCH FROM CURRENT_TIMESTAMP)::bigint,
                'Synthetic artist ' || (1 + n % 20000), 'Synthetic album ' || n, 'Synthetic track', CURRENT_TIMESTAMP FROM generate_series(1, 45000) n", [$this->account->id, $this->snapshot->id]);
        DB::statement("INSERT INTO activity.listen_identities (listening_event_id, artist_mbids, created_at, updated_at)
            SELECT md5('listen-' || n)::uuid, jsonb_build_array(md5('mbid-' || (1 + n % 20000))::uuid::text), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM generate_series(1, 45000) n");
        $this->plex('Synthetic artist 1', '101');
        for ($i = 1; $i <= 250; $i++) {
            $this->plex('Owned synthetic album '.$i, (string) (1000 + $i), 'album', '101');
        }
        $this->pick();
        $lb = Mockery::mock(ListenBrainzClient::class);
        $lb->shouldReceive('configured')->andReturnTrue();
        $number = 70000;
        $lb->shouldReceive('artistRadio')->andReturnUsing(function () use (&$number) {
            return [$this->mbid(++$number) => 'Synthetic candidate '.$number];
        });
        $lb->shouldReceive('artistPopularity')->andReturn([]);
        $lb->shouldReceive('topReleaseGroups')->andReturnUsing(fn ($id) => [['release_group_mbid' => $this->mbid(1000 + (int) substr($id, 0, 8))]]);
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id) => ['id' => $id, 'title' => 'Synthetic discovery album']);
        $albums->shouldReceive('prepare')->andReturnUsing(fn ($group, $artist, $name) => [
            'artist_mbid' => $artist, 'artist_name' => $name, 'title' => $group['title'], 'release_group_mbid' => $group['id'],
            'release_type' => 'Album', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);
        $this->app->instance(ListenBrainzClient::class, $lb);
        Http::fake(['https://musicbrainz.test/ws/2/artist/*' => fn ($request) => Http::response([
            'id' => basename(parse_url($request->url(), PHP_URL_PATH)), 'genres' => [], 'tags' => [],
        ], 200, ['Content-Type' => 'application/json'])]);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $peak = memory_get_peak_usage(true);
        $this->assertSame('completed', $batch->status);
        $this->assertSame(30, $batch->summary['seeds']);
        $this->assertSame(10, $batch->summary['picks']);
        $this->assertLessThan(128 * 1024 * 1024, $peak);
        echo "\nSynthetic discovery peak: ".number_format($peak / 1024 / 1024, 1)." MiB\n";
    }
}
