<?php

namespace App\Music\Discovery;

use Illuminate\Support\Collection;

class RecommendationDiversifier
{
    /** @param Collection<int, array<string, mixed>> $items
     * @return Collection<int, array<string, mixed>>
     */
    public function home(Collection $items, int $limit, string $seed, array $initialArtistCounts = []): Collection
    {
        return $this->select($items, $limit, $seed, fn (array $item): array => [
            'id' => (string) data_get($item, 'album.id'),
            'artist' => (string) (data_get($item, 'album.artist.id') ?? data_get($item, 'album.artist.name', '')),
            'genres' => collect(data_get($item, 'album.genres', []))->filter(fn ($genre): bool => is_string($genre))->values()->all(),
            'era' => $this->era(data_get($item, 'album.first_release_date.year') ?? data_get($item, 'album.year')),
            'score' => (float) ($item['rank_score'] ?? 0),
            'cooled' => (bool) ($item['recently_presented'] ?? false),
        ], $initialArtistCounts);
    }

    /** @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function external(array $items, int $limit, string $seed): array
    {
        return $this->select(collect($items), $limit, $seed, function (array $item): array {
            $credits = is_array(data_get($item, 'release_group.artist-credit')) ? data_get($item, 'release_group.artist-credit') : [];
            $firstCredit = collect($credits)->first(fn ($credit): bool => is_array($credit));
            $genres = collect(data_get($item, 'release_group.genres', []))
                ->map(fn ($genre) => is_array($genre) ? ($genre['name'] ?? null) : null)
                ->filter(fn ($genre): bool => is_string($genre))
                ->values()
                ->all();

            return [
                'id' => (string) ($item['entity_id'] ?? data_get($item, 'release_group.id', '')),
                'artist' => (string) (data_get($firstCredit, 'artist.id') ?? data_get($firstCredit, 'name', '')),
                'genres' => $genres,
                'era' => $this->era(substr((string) data_get($item, 'release_group.first-release-date', ''), 0, 4)),
                'score' => (float) ($item['raw_score'] ?? 0),
                'cooled' => (bool) ($item['recently_presented'] ?? false),
            ];
        })->all();
    }

    /** @param Collection<int, array<string, mixed>> $items
     * @param  callable(array<string,mixed>):array{id:string,artist:string,genres:list<string>,era:?int,score:float,cooled:bool}  $facts
     * @return Collection<int, array<string, mixed>>
     */
    private function select(Collection $items, int $limit, string $seed, callable $facts, array $initialArtistCounts = []): Collection
    {
        $remaining = $items->unique(fn (array $item): string => $facts($item)['id'])->values();
        $selected = collect();
        $artistCounts = $initialArtistCounts;
        $genreCounts = [];
        $eraCounts = [];
        $artistCap = max(1, (int) config('discovery.artist_cap_per_module', 2));
        while ($selected->count() < $limit && $remaining->isNotEmpty()) {
            $choice = $remaining->sortBy(function (array $item) use ($artistCap, $artistCounts, $eraCounts, $facts, $genreCounts, $seed): string {
                $itemFacts = $facts($item);
                $artistCount = $itemFacts['artist'] === '' ? 0 : ($artistCounts[$itemFacts['artist']] ?? 0);
                $genrePenalty = $itemFacts['genres'] === [] ? 0 : min(array_map(fn (string $genre): int => $genreCounts[mb_strtolower($genre)] ?? 0, $itemFacts['genres']));
                $eraPenalty = $itemFacts['era'] === null ? 0 : ($eraCounts[$itemFacts['era']] ?? 0);
                $scoreBand = 10 - (int) floor(min(1, max(0, $itemFacts['score'])) * 10);

                return sprintf('%d:%d:%02d:%03d:%03d:%s', $artistCount >= $artistCap ? 1 : 0, $itemFacts['cooled'] ? 1 : 0, $scoreBand, $genrePenalty, $eraPenalty, hash('sha256', "{$seed}|{$itemFacts['id']}"));
            })->first();
            if ($choice === null) {
                break;
            }
            $choiceFacts = $facts($choice);
            $selected->push($choice);
            $remaining = $remaining->reject(fn (array $item): bool => $facts($item)['id'] === $choiceFacts['id'])->values();
            if ($choiceFacts['artist'] !== '') {
                $artistCounts[$choiceFacts['artist']] = ($artistCounts[$choiceFacts['artist']] ?? 0) + 1;
            }
            foreach ($choiceFacts['genres'] as $genre) {
                $key = mb_strtolower($genre);
                $genreCounts[$key] = ($genreCounts[$key] ?? 0) + 1;
            }
            if ($choiceFacts['era'] !== null) {
                $eraCounts[$choiceFacts['era']] = ($eraCounts[$choiceFacts['era']] ?? 0) + 1;
            }
        }

        return $selected;
    }

    private function era(mixed $year): ?int
    {
        return is_numeric($year) && (int) $year > 0 ? intdiv((int) $year, 10) * 10 : null;
    }
}
