<?php

namespace App\Music\Descriptions;

use App\Models\CatalogEntity;
use App\Models\EntityNarrative;
use App\Models\RecommendationRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class NarrativeCoverageReport
{
    private const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    /**
     * @return array{
     *   generated_at:string,
     *   coverage:list<array{entity_kind:string,eligible:int,ready:int,missing:int,stale:int,failed:int,unattempted:int}>,
     *   breakdowns:list<array{entity_kind:string,provider:string,language:string,status:string,records:int}>
     * }
     */
    public function generate(): array
    {
        $coverage = [];
        $breakdowns = collect();

        foreach (['album' => $this->eligibleEntityIds('album'), 'artist' => $this->eligibleEntityIds('artist')] as $entityKind => $entityIds) {
            $narratives = EntityNarrative::query()
                ->whereIn('entity_id', $entityIds)
                ->where('kind', 'description')
                ->get(['entity_id', 'provider_slug', 'language', 'status', 'fetched_at']);
            $statusesByEntity = $narratives
                ->groupBy('entity_id')
                ->map(fn (Collection $records): Collection => $records
                    ->map(fn (EntityNarrative $narrative): string => $this->effectiveStatus($narrative))
                    ->unique());

            $coverage[] = [
                'entity_kind' => $entityKind,
                'eligible' => $entityIds->count(),
                'ready' => $statusesByEntity->filter->contains('ready')->count(),
                'missing' => $statusesByEntity->filter->contains('missing')->count(),
                'stale' => $statusesByEntity->filter->contains('stale')->count(),
                'failed' => $statusesByEntity->filter->contains('failed')->count(),
                'unattempted' => $entityIds->diff($statusesByEntity->keys())->count(),
            ];

            $breakdowns = $breakdowns->concat($narratives
                ->groupBy(fn (EntityNarrative $narrative): string => implode('|', [
                    $narrative->provider_slug,
                    $narrative->language,
                    $this->effectiveStatus($narrative),
                ]))
                ->map(function (Collection $records) use ($entityKind): array {
                    $narrative = $records->first();

                    return [
                        'entity_kind' => $entityKind,
                        'provider' => $narrative->provider_slug,
                        'language' => $narrative->language,
                        'status' => $this->effectiveStatus($narrative),
                        'records' => $records->count(),
                    ];
                }));
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'coverage' => $coverage,
            'breakdowns' => $breakdowns
                ->sortBy([
                    ['entity_kind', 'asc'],
                    ['provider', 'asc'],
                    ['language', 'asc'],
                    ['status', 'asc'],
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return Collection<int, string> */
    public function eligibleEntityIds(string $entityKind): Collection
    {
        return match ($entityKind) {
            'album' => $this->eligibleAlbumIds(),
            'artist' => $this->eligibleArtistIds(),
            default => collect(),
        };
    }

    public function effectiveStatus(EntityNarrative $narrative): string
    {
        if ($narrative->status !== 'ready') {
            return $narrative->status;
        }

        $freshForDays = $narrative->provider_slug === 'theaudiodb' ? 30 : 7;

        return $narrative->fetched_at->lt(now()->subDays($freshForDays)) ? 'stale' : 'ready';
    }

    /** @return Collection<int, string> */
    private function eligibleAlbumIds(): Collection
    {
        $latestRunId = RecommendationRun::query()
            ->where('intent', 'beyond_library')
            ->where('status', 'completed')
            ->whereHas('items')
            ->latest('generated_at')
            ->value('id');

        return CatalogEntity::query()
            ->where('kind', 'release_group')
            ->where('status', 'active')
            ->whereHas('releaseGroup')
            ->whereHas('identifiers', fn (Builder $query) => $query
                ->where('namespace', 'musicbrainz.release_group')
                ->where('status', 'active')
                ->whereRaw('value ~* ?', [self::UUID_PATTERN]))
            ->where(function (Builder $query) use ($latestRunId): void {
                $query->whereHas('releaseGroup.holdings.plexAlbum', fn (Builder $query) => $query->whereNull('removed_at'));
                if ($latestRunId !== null) {
                    $query->orWhereExists(fn ($query) => $query
                        ->selectRaw('1')
                        ->from('discovery.recommendation_items')
                        ->whereColumn('discovery.recommendation_items.entity_id', 'catalog.entities.id')
                        ->where('discovery.recommendation_items.run_id', $latestRunId));
                }
            })
            ->pluck('catalog.entities.id');
    }

    /** @return Collection<int, string> */
    private function eligibleArtistIds(): Collection
    {
        return CatalogEntity::query()
            ->where('kind', 'agent')
            ->where('status', 'active')
            ->whereHas('agent')
            ->whereHas('identifiers', fn (Builder $query) => $query
                ->where('namespace', 'musicbrainz.artist')
                ->where('status', 'active'))
            ->whereRaw("(select count(*) from catalog.external_identifiers where entity_id = catalog.entities.id and namespace = 'musicbrainz.artist' and status = 'active') = 1")
            ->whereRaw("exists (select 1 from catalog.external_identifiers where entity_id = catalog.entities.id and namespace = 'musicbrainz.artist' and status = 'active' and value ~* ?)", [self::UUID_PATTERN])
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('library.plex_entity_matches as artist_matches')
                ->join('library.plex_items as artist_items', 'artist_items.id', '=', 'artist_matches.plex_item_id')
                ->join('library.plex_item_guids as artist_guids', 'artist_guids.plex_item_id', '=', 'artist_items.id')
                ->join('catalog.external_identifiers as artist_identifiers', 'artist_identifiers.entity_id', '=', 'artist_matches.entity_id')
                ->whereColumn('artist_matches.entity_id', 'catalog.entities.id')
                ->whereRaw('lower(artist_guids.value) = lower(artist_identifiers.value)')
                ->where('artist_matches.match_scope', 'agent')
                ->where('artist_matches.status', 'confirmed')
                ->where('artist_matches.method', 'external_id')
                ->where('artist_items.item_type', 'artist')
                ->whereNull('artist_items.removed_at')
                ->where('artist_guids.namespace', 'mbid')
                ->whereRaw('artist_guids.value ~* ?', [self::UUID_PATTERN])
                ->whereRaw("(select count(distinct lower(valid_artist_guids.value)) from library.plex_item_guids as valid_artist_guids where valid_artist_guids.plex_item_id = artist_items.id and valid_artist_guids.namespace = 'mbid' and valid_artist_guids.value ~* ?) = 1", [self::UUID_PATTERN])
                ->where('artist_identifiers.namespace', 'musicbrainz.artist')
                ->where('artist_identifiers.status', 'active'))
            ->pluck('catalog.entities.id');
    }
}
