<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Http\Presenters\AlbumPresenter;
use App\Http\Presenters\ArtistNamePresenter;
use App\Http\Presenters\ArtistRelationshipPresenter;
use App\Http\Presenters\ArtworkPresenter;
use App\Http\Presenters\CatalogPortraitPresenter;
use App\Http\Presenters\NarrativePresenter;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Music\ArtistLinkCurator;
use App\Music\CanonicalEntityResolver;
use App\Music\Discogs\DiscogsMetadataPresenter;
use App\Music\Discovery\ArtistDiscographyRefreshService;
use App\Music\Discovery\ArtistSeedService;
use App\Music\Discovery\BeyondLibraryDiscoveryService;
use App\Music\Library\AlbumFactsService;
use App\Music\QobuzDestinationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArtistController extends Controller
{
    public function __invoke(Request $request, string $id, AlbumPresenter $presenter, AlbumListStatePresenter $listStates, ArtworkPresenter $artworkPresenter, CatalogPortraitPresenter $portraitPresenter, NarrativePresenter $narrativePresenter, ArtistRelationshipPresenter $relationshipPresenter, ArtistNamePresenter $artistNames, ArtistSeedService $artistSeeds, ArtistDiscographyRefreshService $discographyRefreshes, CanonicalEntityResolver $resolver, AlbumFactsService $factsService, ArtistLinkCurator $linkCurator, BeyondLibraryDiscoveryService $beyondLibrary, DiscogsMetadataPresenter $discogs, QobuzDestinationResolver $qobuz): JsonResponse
    {
        $entity = $resolver->resolve($id, 'agent');
        abort_if($entity === null, 404);
        $id = $entity->id;
        $entity->load(['metadata', 'identifiers' => fn ($query) => $query
            ->where('namespace', 'musicbrainz.artist')->where('status', 'active')]);
        $match = PlexEntityMatch::query()
            ->where('entity_id', $id)
            ->where('match_scope', 'agent')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->whereHas('item', fn ($query) => $query->whereNull('removed_at')->where('item_type', 'artist'))
            ->with([
                'entity.metadata',
                'entity.identifiers' => fn ($query) => $query
                    ->where('namespace', 'musicbrainz.artist')
                    ->where('status', 'active'),
                'item',
            ])
            ->orderByRaw("case when status = 'confirmed' then 0 else 1 end")
            ->orderBy('id')
            ->first();
        $artist = $match?->item;
        $artist?->load(['artwork', 'matches.entity.metadata']);
        $exactPlexItemId = PlexEntityMatch::query()
            ->where('entity_id', $id)
            ->where('match_scope', 'agent')
            ->where('status', 'confirmed')
            ->whereHas('item', fn ($query) => $query
                ->whereNull('removed_at')->where('item_type', 'artist')
                ->whereHas('library', fn ($libraries) => $libraries
                    ->where('section_uuid', (string) config('services.plex.expected_library_uuid'))
                    ->whereHas('server', fn ($servers) => $servers->where('machine_identifier', (string) config('services.plex.expected_machine_identifier')))))
            ->value('plex_item_id');

        $albumItems = $artist === null ? collect() : PlexItem::query()
            ->where('plex_library_id', $artist->plex_library_id)
            ->where('parent_rating_key', $artist->rating_key)
            ->where('item_type', 'album')
            ->whereNull('removed_at')
            ->whereHas('matches', fn ($query) => $query
                ->where('match_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate']))
            ->with(['artwork', 'guids', 'matches.entity.metadata'])
            ->orderByDesc('year')
            ->orderBy('sort_title')
            ->get();
        $facts = $factsService->forAlbums($albumItems);
        $musicBrainzIds = $entity->identifiers->pluck('value')->values();
        $exactMusicBrainzId = $musicBrainzIds->count() === 1 && Str::isUuid($musicBrainzIds->first())
            ? strtolower($musicBrainzIds->first())
            : null;
        $albums = $albumItems
            ->map(fn (PlexItem $album): array => $presenter->summary(
                $album,
                $artist,
                $facts["{$album->plex_library_id}:{$album->rating_key}"] ?? [],
            ))
            ->unique('id')
            ->values();
        $artistName = $artistNames->present(
            $entity->canonical_name,
            $entity->metadata?->primary_type,
            $entity->metadata?->disambiguation,
        );

        $data = [
            'id' => $id,
            ...$artistName,
            'portrait' => $portraitPresenter->forEntities([$id])[$id] ?? $artworkPresenter->for($artist),
            'type' => $entity->metadata?->primary_type,
            'area' => $entity->metadata?->area,
            'begin_date' => $this->partialDate($entity->metadata, 'begin'),
            'end_date' => $this->partialDate($entity->metadata, 'end'),
            'genres' => collect($entity->metadata?->genres ?? [])->pluck('name')->take(8)->values(),
            'disambiguation' => $entity->metadata?->disambiguation,
            'external_links' => $linkCurator->curate(
                $entity->metadata?->external_links ?? [],
                $exactMusicBrainzId,
            ),
            'qobuz' => $qobuz->resolve('artist', $entity->metadata?->external_links ?? [], $entity->canonical_name),
            'description' => $narrativePresenter->description($id),
            'relationships' => $relationshipPresenter->present($id),
            'follow_state' => $artistSeeds->state((string) $request->user()->id, $id),
            'plex_item_id' => $exactPlexItemId,
            'open_in_plex_available' => $exactPlexItemId !== null,
            'open_in_plex_status' => $exactPlexItemId === null ? 'unavailable' : 'exact',
            'albums' => $albums,
            'recommended_albums' => $exactMusicBrainzId === null
                ? []
                : $beyondLibrary->forArtist((string) $request->user()->id, $id, $exactMusicBrainzId),
            'discogs' => $discogs->forEntity($id),
        ];
        $discographyRefreshes->request($id);

        return response()->json(['data' => $listStates->overlay($data, (string) $request->user()->id)]);
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
}
