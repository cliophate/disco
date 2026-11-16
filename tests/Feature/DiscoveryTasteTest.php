<?php

namespace Tests\Feature;

use App\Music\Discovery\TasteProfile;
use Tests\DiscoveryTestCase;

class DiscoveryTasteTest extends DiscoveryTestCase
{
    public function test_recent_listening_outweighs_old_volume_and_seed_count_is_bounded(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->listen('Old artist', $this->mbid(1), 1000);
        }
        $this->listen('Recent artist', $this->mbid(2), 2);
        $this->listen('Recent artist', $this->mbid(2), 4);
        $this->listen('Annual artist', $this->mbid(3), 200);
        $this->listen('Annual artist', $this->mbid(3), 200);
        config()->set('discovery.seed_limit', 2);
        $taste = app(TasteProfile::class)->load();
        $this->assertSame([$this->mbid(2), $this->mbid(3)], array_column($taste->seeds(), 'mbid'));
        $this->assertEqualsWithDelta(6.2, $taste->seeds()[0]['score'], 0.001);
        $this->assertGreaterThan($taste->weight(now()->subDays(366)->toImmutable()), $taste->weight(now()->subDays(365)->toImmutable()));
        $this->assertGreaterThan($taste->weight(now()->subDays(91)->toImmutable()), $taste->weight(now()->subDays(90)->toImmutable()));
    }

    public function test_name_only_history_uses_owned_artist_mbid_and_special_artists_never_seed(): void
    {
        $this->artist('Seed artist', $this->mbid(1));
        $this->listen('  SEED   ARTIST ');
        $this->listen('Various Artists', '89ad4ac3-39f7-470e-963a-56509c546377');
        $this->listen('Unidentified artist');
        $taste = app(TasteProfile::class)->load();
        $this->assertSame([$this->mbid(1)], array_column($taste->seeds(), 'mbid'));
        $this->assertSame(1, $taste->lifetimeListens($this->mbid(1), 'Seed Artist'));
    }

    public function test_plex_plays_contribute_without_double_counting_scrobbles(): void
    {
        $this->artist('Seed', $this->mbid(1));
        $this->plex('Seed', '101');
        $this->plex('Owned', '201', 'album', '101', ['view_count' => 3, 'last_viewed_at' => now()]);
        $this->listen('Seed', $this->mbid(1));
        $taste = app(TasteProfile::class)->load();
        $this->assertEqualsWithDelta(9.3, $taste->seeds()[0]['score'], 0.001);
    }

    public function test_unheard_album_matching_uses_group_ids_and_name_fallback(): void
    {
        $this->listen('Artist', $this->mbid(1), album: 'One', groupMbid: $this->mbid(11));
        $this->listen('Artist', album: '  SECOND   Album ');
        $taste = app(TasteProfile::class)->load();
        $this->assertTrue($taste->heardAlbum($this->mbid(11), 'Renamed artist', 'Renamed album'));
        $this->assertTrue($taste->heardAlbum($this->mbid(12), 'ARTIST', 'Second Album'));
        $this->assertFalse($taste->heardAlbum($this->mbid(13), 'Artist', 'Unheard'));
    }
}
