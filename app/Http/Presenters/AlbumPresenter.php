<?php

namespace App\Http\Presenters;

use App\Models\CatalogEntity;
use App\Models\PlexItem;
use App\Music\QobuzDestinationResolver;
use LogicException;

class AlbumPresenter
{
    public function __construct(
        private readonly ArtworkPresenter $artworkPresenter,
        private readonly ArtistNamePresenter $artistNames,
        private readonly QobuzDestinationResolver $qobuz,
    ) {}

    /** @return array<string, mixed> */
    public function summary(PlexItem $album, ?PlexItem $artist = null, array $facts = []): array
    {
        $albumMatches = $album->matches->where('match_scope', 'release_group');
        $selectedMatch = $albumMatches->firstWhere('status', 'confirmed')
            ?? $albumMatches->firstWhere('status', 'candidate');
        $entity = $selectedMatch?->entity;
        if ($entity === null) {
            throw new LogicException('An album summary requires an active release-group match.');
        }
        $releaseMatch = $album->matches
            ->where('match_scope', 'release')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->first(fn ($match): bool => $match->entity?->release?->release_group_id === $entity->id);
        $artistMatches = $artist?->matches?->where('match_scope', 'agent');
        $artistEntity = $artistMatches?->firstWhere('status', 'confirmed')?->entity
            ?? $artistMatches?->firstWhere('status', 'candidate')?->entity;
        $metadata = $entity->metadata;
        $releaseMetadata = $releaseMatch?->entity?->metadata;
        $artistMetadata = $artistEntity?->metadata;
        $artistName = $artistEntity === null ? null : $this->artistNames->present(
            $artist->title,
            $artistMetadata?->primary_type,
            $artistMetadata?->disambiguation,
        );
        $albumGenres = collect($metadata?->genres ?? []);
        $genreBasis = 'album';
        if ($albumGenres->isEmpty()) {
            $albumGenres = collect($artistMetadata?->genres ?? []);
            $genreBasis = $albumGenres->isEmpty() ? null : 'artist';
        }
        $qobuz = $this->qobuz->resolve('album', $metadata?->external_links ?? [], $album->title, $artist?->title);

        return [
            'id' => $entity->id,
            'plex_item_id' => $album->id,
            'title' => $album->title,
            'artist' => $artistEntity === null ? null : [
                'id' => $artistEntity->id,
                ...$artistName,
                'portrait' => $this->artworkPresenter->for($artist),
                'type' => $artistMetadata?->primary_type,
                'area' => $artistMetadata?->area,
                'genres' => collect($artistMetadata?->genres ?? [])->pluck('name')->take(4)->values(),
            ],
            'year' => $album->year,
            'artwork' => $this->artworkPresenter->for($album),
            'added_at' => $album->added_at_plex?->toAtomString(),
            'duration_ms' => $facts['duration_ms'] ?? null,
            'track_count' => $facts['track_count'] ?? null,
            'last_heard_at' => $facts['last_heard_at'] ?? $album->last_viewed_at?->toAtomString(),
            'play_count' => (int) ($facts['play_count'] ?? $album->view_count ?? 0),
            'listening_signals' => [
                'plex' => $facts['plex'] ?? [
                    'album_view_count' => (int) ($facts['play_count'] ?? $album->view_count ?? 0),
                    'played_track_count' => (int) ($facts['played_track_count'] ?? 0),
                    'last_viewed_at' => $facts['last_heard_at'] ?? $album->last_viewed_at?->toAtomString(),
                ],
                'listenbrainz' => $facts['listenbrainz'] ?? [
                    'listen_count' => 0,
                    'first_listened_at' => null,
                    'last_listened_at' => null,
                ],
            ],
            'release_type' => $metadata?->primary_type ?? $entity->releaseGroup?->primary_type,
            'first_release_date' => $this->partialDate($metadata, 'first_release'),
            'genres' => $albumGenres->pluck('name')->take(6)->values(),
            'genre_basis' => $genreBasis,
            'labels' => collect($releaseMetadata?->attributes['labels'] ?? $metadata?->attributes['labels'] ?? [])
                ->filter(fn (array $label): bool => isset($label['name']) && ! in_array(
                    strtolower(trim((string) $label['name'])),
                    ['[none]', '[no label]'],
                    true,
                ))
                ->map(function (array $label): array {
                    $catalogNumber = $label['catalog_number'] ?? null;
                    if (is_string($catalogNumber) && in_array(strtolower(trim($catalogNumber)), ['[none]', '[no catalog number]'], true)) {
                        $catalogNumber = null;
                    }

                    return ['name' => $label['name'], 'catalog_number' => $catalogNumber];
                })->take(6)->values(),
            'disambiguation' => $metadata?->disambiguation,
            'sources' => array_values(array_filter(['Plex', $metadata === null && $releaseMetadata === null ? null : 'MusicBrainz'])),
            'owned' => true,
            'metadata_status' => $metadata !== null ? 'enriched' : ($selectedMatch->status === 'confirmed' ? 'identified' : 'candidate'),
            'identity_status' => $selectedMatch->status,
            'open_in_plex_available' => true,
            'open_in_plex_status' => (int) ($facts['holding_count'] ?? 1) > 1 ? 'choice-required' : 'exact',
            'qobuz_search_url' => $this->qobuzSearchUrl($album->title, $artist?->title),
            'qobuz' => $qobuz,
            'list_state' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function external(CatalogEntity $entity): array
    {
        if ($entity->kind !== 'release_group') {
            throw new LogicException('An external album summary requires a release-group entity.');
        }
        $metadata = $entity->metadata;
        $credits = collect($metadata?->artist_credit ?? []);
        $artistName = $credits->map(fn (array $credit): string => (string) ($credit['name'] ?? '').(string) ($credit['joinphrase'] ?? ''))->implode('');
        $artistName = trim($artistName);
        $artistId = $credits->count() === 1 && is_string($credits->first()['artist_entity_id'] ?? null)
            ? $credits->first()['artist_entity_id']
            : null;
        $qobuz = $this->qobuz->resolve('album', $metadata?->external_links ?? [], $entity->canonical_name, $artistName === '' ? null : $artistName);

        return [
            'id' => $entity->id,
            'plex_item_id' => null,
            'title' => $entity->canonical_name,
            'artist' => $artistName === '' ? null : [
                'id' => $artistId,
                'name' => $artistName,
                'credited_name' => null,
                'portrait' => null,
                'type' => null,
                'area' => null,
                'genres' => [],
            ],
            'year' => $metadata?->first_release_year,
            'artwork' => $this->artworkPresenter->forEntity($entity),
            'added_at' => null,
            'duration_ms' => null,
            'track_count' => null,
            'last_heard_at' => null,
            'play_count' => null,
            'listening_signals' => null,
            'release_type' => $metadata?->primary_type,
            'first_release_date' => $this->partialDate($metadata, 'first_release'),
            'genres' => collect($metadata?->genres ?? [])->pluck('name')->take(6)->values(),
            'genre_basis' => collect($metadata?->genres ?? [])->isEmpty() ? null : 'album',
            'labels' => [],
            'disambiguation' => $metadata?->disambiguation ?? $entity->disambiguation,
            'sources' => array_values(array_filter([
                $metadata === null ? null : 'MusicBrainz',
                in_array($entity->artwork?->status, ['ready', 'stale'], true) ? 'Cover Art Archive' : null,
            ])),
            'owned' => false,
            'metadata_status' => $metadata === null ? 'identified' : 'enriched',
            'identity_status' => 'confirmed',
            'open_in_plex_available' => false,
            'open_in_plex_status' => 'unavailable',
            'qobuz_search_url' => $this->qobuzSearchUrl($entity->canonical_name, $artistName === '' ? null : $artistName),
            'qobuz' => $qobuz,
            'list_state' => null,
        ];
    }

    /** @return array{year:int,month:?int,day:?int,precision:string}|null */
    private function partialDate(mixed $metadata, string $prefix): ?array
    {
        $year = $metadata?->{"{$prefix}_year"};
        if ($year === null) {
            return null;
        }

        return [
            'year' => (int) $year,
            'month' => $metadata?->{"{$prefix}_month"} === null ? null : (int) $metadata->{"{$prefix}_month"},
            'day' => $metadata?->{"{$prefix}_day"} === null ? null : (int) $metadata->{"{$prefix}_day"},
            'precision' => (string) $metadata?->{"{$prefix}_precision"},
        ];
    }

    private function qobuzSearchUrl(string $title, ?string $artist): string
    {
        return $this->qobuz->searchUrl($title, $artist);
    }
}
