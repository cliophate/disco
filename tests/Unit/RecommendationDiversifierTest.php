<?php

namespace Tests\Unit;

use App\Music\Discovery\RecommendationDiversifier;
use Tests\TestCase;

class RecommendationDiversifierTest extends TestCase
{
    public function test_selection_is_reproducible_caps_artists_and_uses_cooldown_with_sparse_fallback(): void
    {
        config()->set('discovery.artist_cap_per_module', 2);
        $items = collect([
            $this->item('a1', 'artist-a', 'Rock', 1991, 0.9),
            $this->item('a2', 'artist-a', 'Rock', 1992, 0.9),
            $this->item('a3', 'artist-a', 'Rock', 1993, 0.9),
            $this->item('b1', 'artist-b', 'Jazz', 2001, 0.9),
            $this->item('c1', 'artist-c', 'Soul', 2011, 0.9),
            $this->item('d1', 'artist-d', 'Electronic', 2021, 1.0, true),
        ]);
        $diversifier = app(RecommendationDiversifier::class);

        $first = $diversifier->home($items, 4, 'stable-seed');
        $second = $diversifier->home($items, 4, 'stable-seed');

        $this->assertSame($first->pluck('album.id')->all(), $second->pluck('album.id')->all());
        $this->assertLessThanOrEqual(2, $first->where('album.artist.id', 'artist-a')->count());
        $this->assertFalse($first->pluck('album.id')->contains('d1'));

        $requalified = $items->map(fn (array $item): array => data_get($item, 'album.id') === 'd1' ? [...$item, 'recently_presented' => false] : $item);
        $this->assertTrue($diversifier->home($requalified, 4, 'stable-seed')->pluck('album.id')->contains('d1'));

        $sparse = $diversifier->home(collect([
            $this->item('only-1', 'artist-a', 'Rock', 1991, 0.8, true),
            $this->item('only-2', 'artist-a', 'Rock', 1992, 0.7, true),
        ]), 8, 'stable-seed');
        $this->assertEqualsCanonicalizing(['only-1', 'only-2'], $sparse->pluck('album.id')->all());
    }

    /** @return array<string, mixed> */
    private function item(string $id, string $artist, string $genre, int $year, float $score, bool $cooled = false): array
    {
        return [
            'album' => ['id' => $id, 'artist' => ['id' => $artist, 'name' => $artist], 'genres' => [$genre], 'year' => $year, 'first_release_date' => ['year' => $year]],
            'rank_score' => $score,
            'recently_presented' => $cooled,
        ];
    }
}
