<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Http\Presenters\AlbumPresenter;
use App\Http\Presenters\CreditPresenter;
use App\Http\Presenters\NarrativePresenter;
use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexItem;
use App\Music\Activity\TrackListeningService;
use App\Music\CanonicalEntityResolver;
use App\Music\Discogs\DiscogsMetadataPresenter;
use App\Music\Discovery\BeyondLibraryDiscoveryService;
use App\Music\Library\AlbumFactsService;
use App\Music\Plex\PlexPlaybackContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AlbumController extends Controller
{
    public function __invoke(Request $request, string $id, AlbumPresenter $presenter, AlbumListStatePresenter $listStates, NarrativePresenter $narrativePresenter, CreditPresenter $creditPresenter, CanonicalEntityResolver $resolver, AlbumFactsService $factsService, BeyondLibraryDiscoveryService $beyondLibrary, PlexPlaybackContextService $playbackContext, TrackListeningService $trackListening, DiscogsMetadataPresenter $discogs): JsonResponse
    {
        $canonical = $resolver->resolve($id, 'release_group');
        abort_if($canonical === null, 404);
        $id = $canonical->id;
        $holdings = Holding::query()
            ->where('release_group_id', $id)
            ->whereHas('plexAlbum', fn ($query) => $query->whereNull('removed_at')->where('item_type', 'album'))
            ->whereHas('plexAlbum.matches', fn ($query) => $query
                ->where('entity_id', $id)
                ->where('match_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate']))
            ->with(['plexAlbum.artwork', 'release.media'])
            ->orderByDesc('is_primary_playback_copy')
            ->orderBy('id')
            ->get();
        if ($holdings->isEmpty()) {
            $entity = CatalogEntity::query()
                ->whereKey($id)
                ->where('kind', 'release_group')
                ->where('status', 'active')
                ->with(['metadata', 'artwork', 'narratives'])
                ->firstOrFail();
            $basisMbid = strtolower((string) ($entity->metadata?->attributes['basis_release_mbid'] ?? ''));
            $basisRelease = ExternalIdentifier::query()
                ->where('namespace', 'musicbrainz.release')
                ->where('value', $basisMbid)
                ->where('status', 'active')
                ->with('entity.release.media.tracks.recording.entity.metadata')
                ->first()?->entity?->release;
            if ($basisRelease?->release_group_id !== $entity->id || ! $basisRelease->media()->exists()) {
                $basisRelease = $entity->releaseGroup?->releases()
                    ->whereHas('media')
                    ->with('media.tracks.recording.entity.metadata')
                    ->orderBy('release_year')
                    ->orderBy('entity_id')
                    ->first();
            }
            $tracks = $basisRelease?->media->flatMap(fn ($medium) => $medium->tracks->map(fn ($track): array => [
                'id' => $track->id,
                'position' => $track->position,
                'disc' => $medium->position,
                'title' => $track->title,
                'duration_ms' => $track->duration_ms,
                '_artist_credit' => $track->recording?->entity?->metadata?->artist_credit ?? [],
                '_credit_subject_id' => $track->recording_id,
                '_recording_id' => $track->recording_id,
            ]))->values() ?? collect();
            $tracks = $this->withFeaturedArtists($tracks, $entity->metadata?->artist_credit ?? []);
            $creditSubjects = [$entity->id, $basisRelease?->entity_id, ...$tracks->pluck('_credit_subject_id')->filter()->all()];
            $credits = $creditPresenter->forSubjects($creditSubjects);
            $tracks = $this->attachCredits($tracks, $credits);
            $tracks = $trackListening->attach($tracks, (string) $request->user()->id);
            $duration = $tracks->isNotEmpty() && $tracks->every(fn (array $track): bool => $track['duration_ms'] !== null)
                ? $tracks->sum('duration_ms')
                : null;
            $description = $narrativePresenter->description($entity->id);

            $data = [
                ...$presenter->external($entity),
                'duration_ms' => $duration,
                'track_count' => $tracks->count() ?: null,
                'basis_release_id' => $basisRelease?->entity_id,
                'basis_plex_item_id' => null,
                'tracks' => $tracks,
                'formats' => $basisRelease?->media->pluck('format')->filter()->unique()->values() ?? collect(),
                'open_in_plex_status' => 'unavailable',
                'holdings' => [],
                'credits' => $creditPresenter->combine($credits, [$entity->id, $basisRelease?->entity_id]),
                'recommendation' => $beyondLibrary->forEntity((string) $request->user()->id, $entity->id),
                'description' => $description,
                'plex_playback_context' => $playbackContext->forReleaseGroup($entity->id),
                'discogs' => $discogs->forEntity($entity->id),
            ];

            return response()->json(['data' => $listStates->overlay($data, (string) $request->user()->id)]);
        }
        $selectedHolding = $holdings->first();
        $album = $selectedHolding->plexAlbum;
        $album->load(['artwork', 'guids', 'matches.entity.metadata', 'matches.entity.release']);

        $artist = PlexItem::query()
            ->where('plex_library_id', $album->plex_library_id)
            ->where('rating_key', $album->parent_rating_key)
            ->where('item_type', 'artist')
            ->whereNull('removed_at')
            ->whereHas('matches', fn ($query) => $query
                ->where('match_scope', 'agent')
                ->whereIn('status', ['confirmed', 'candidate']))
            ->with(['artwork', 'matches.entity.metadata'])
            ->first();

        $tracks = PlexItem::query()
            ->where('plex_library_id', $album->plex_library_id)
            ->where('parent_rating_key', $album->rating_key)
            ->where('item_type', 'track')
            ->whereNull('removed_at')
            ->with(['matches.entity.metadata', 'mediaParts'])
            ->orderBy('disc_number')
            ->orderBy('index_number')
            ->get()
            ->map(function (PlexItem $track): array {
                $recording = $track->matches
                    ->where('match_scope', 'recording')
                    ->firstWhere('status', 'confirmed')?->entity;

                return [
                    'id' => $recording?->id ?? $track->id,
                    'position' => $track->index_number ?? 0,
                    'disc' => $track->disc_number ?? 1,
                    'title' => $track->title,
                    'duration_ms' => $track->duration_ms ?? 0,
                    '_artist_credit' => $recording?->metadata?->artist_credit ?? [],
                    '_credit_subject_id' => $recording?->id,
                    '_recording_id' => $recording?->id,
                    '_plex_item_id' => $track->id,
                    'playback' => [
                        'plex_item_id' => $track->id,
                        'sources' => $track->mediaParts->map(fn ($part): array => [
                            'id' => $part->id,
                            'mime_type' => $part->browserMimeType(),
                            'container' => $part->container,
                            'codec' => $part->audio_codec,
                            'channels' => $part->channels,
                            'bit_depth' => $part->bit_depth,
                            'sample_rate_hz' => $part->sample_rate_hz,
                        ])->filter(fn (array $source): bool => $source['mime_type'] !== null)->values(),
                    ],
                ];
            })->values();

        $releaseGroupEntity = $album->matches
            ->where('match_scope', 'release_group')
            ->firstWhere('status', 'confirmed')?->entity
            ?? $album->matches->where('match_scope', 'release_group')->firstWhere('status', 'candidate')?->entity;
        $tracks = $this->withFeaturedArtists($tracks, $releaseGroupEntity?->metadata?->artist_credit ?? []);
        $creditSubjects = [$releaseGroupEntity?->id, $selectedHolding?->release_id, ...$tracks->pluck('_credit_subject_id')->filter()->all()];
        $credits = $creditPresenter->forSubjects($creditSubjects);
        $tracks = $this->attachCredits($tracks, $credits);
        $tracks = $trackListening->attach($tracks, (string) $request->user()->id);

        $facts = $factsService->forAlbums(collect([$album]));

        $data = [
            ...$presenter->summary($album, $artist, $facts["{$album->plex_library_id}:{$album->rating_key}"] ?? []),
            'basis_release_id' => $selectedHolding?->release_id,
            'basis_plex_item_id' => $album->id,
            'tracks' => $tracks,
            'formats' => $selectedHolding?->release?->media->pluck('format')->filter()->unique()->values() ?? collect(),
            'open_in_plex_status' => $holdings->count() > 1 ? 'choice-required' : 'exact',
            'holdings' => $holdings->map(fn (Holding $holding): array => [
                'id' => $holding->id,
                'release_id' => $holding->release_id,
                'plex_item_id' => $holding->plexAlbum->id,
                'title' => $holding->plexAlbum->title,
                'year' => $holding->plexAlbum->year,
                'formats' => $holding->release?->media->pluck('format')->filter()->unique()->values() ?? collect(),
                'edition_summary' => $holding->release?->edition_summary,
            ])->values(),
            'credits' => $creditPresenter->combine($credits, [$releaseGroupEntity?->id, $selectedHolding?->release_id]),
            'recommendation' => null,
            'description' => $narrativePresenter->description($id),
            'plex_playback_context' => $playbackContext->forReleaseGroup($id),
            'discogs' => $discogs->forEntity($id),
        ];

        return response()->json(['data' => $listStates->overlay($data, (string) $request->user()->id)]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tracks
     * @param  list<array<string, mixed>>  $albumCredits
     * @return Collection<int, array<string, mixed>>
     */
    private function withFeaturedArtists(Collection $tracks, array $albumCredits): Collection
    {
        $trackCredits = $tracks->pluck('_artist_credit')->flatten(1)->filter(fn ($credit): bool => is_array($credit));
        $entityIds = $trackCredits->pluck('artist_entity_id')->filter(fn ($id): bool => is_string($id) && Str::isUuid($id))->unique();
        $validEntities = CatalogEntity::query()
            ->whereIn('id', $entityIds)
            ->where('kind', 'agent')
            ->where('status', 'active')
            ->pluck('id')
            ->flip();
        $mbids = $trackCredits->pluck('artist_mbid')->filter(fn ($mbid): bool => is_string($mbid) && Str::isUuid($mbid))->map('strtolower')->unique();
        $entitiesByMbid = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.artist')
            ->where('status', 'active')
            ->whereIn('value', $mbids)
            ->whereHas('entity', fn ($query) => $query->where('kind', 'agent')->where('status', 'active'))
            ->get(['entity_id', 'value'])
            ->mapWithKeys(fn (ExternalIdentifier $identifier): array => [strtolower($identifier->value) => $identifier->entity_id]);
        $albumKeys = collect($albumCredits)
            ->filter(fn ($credit): bool => is_array($credit))
            ->flatMap(fn (array $credit): array => $this->creditKeys($credit))
            ->flip();

        return $tracks->map(function (array $track) use ($albumKeys, $entitiesByMbid, $validEntities): array {
            $seen = [];
            $featured = collect($track['_artist_credit'] ?? [])->map(function ($credit) use ($albumKeys, $entitiesByMbid, $validEntities, &$seen): ?array {
                if (! is_array($credit) || ! is_string($credit['name'] ?? null) || trim($credit['name']) === '') {
                    return null;
                }
                $keys = $this->creditKeys($credit);
                if (collect($keys)->contains(fn (string $key): bool => $albumKeys->has($key))) {
                    return null;
                }
                $entityId = is_string($credit['artist_entity_id'] ?? null) && $validEntities->has($credit['artist_entity_id'])
                    ? $credit['artist_entity_id']
                    : (is_string($credit['artist_mbid'] ?? null) ? $entitiesByMbid->get(strtolower($credit['artist_mbid'])) : null);
                $dedupeKey = $entityId ?? 'name:'.mb_strtolower(trim($credit['name']));
                if (isset($seen[$dedupeKey])) {
                    return null;
                }
                $seen[$dedupeKey] = true;

                return ['id' => $entityId, 'name' => trim($credit['name'])];
            })->filter()->values()->all();
            unset($track['_artist_credit']);
            $track['featured_artists'] = $featured;

            return $track;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tracks
     * @param  array<string, array{status:string,groups:array}>  $credits
     * @return Collection<int, array<string, mixed>>
     */
    private function attachCredits(Collection $tracks, array $credits): Collection
    {
        return $tracks->map(function (array $track) use ($credits): array {
            $subjectId = $track['_credit_subject_id'] ?? null;
            unset($track['_credit_subject_id']);
            $track['credits'] = is_string($subjectId) && isset($credits[$subjectId])
                ? $credits[$subjectId]
                : ['status' => 'unavailable', 'groups' => []];

            return $track;
        });
    }

    /** @param array<string, mixed> $credit
     * @return list<string>
     */
    private function creditKeys(array $credit): array
    {
        return array_values(array_filter([
            is_string($credit['artist_entity_id'] ?? null) ? 'entity:'.strtolower($credit['artist_entity_id']) : null,
            is_string($credit['artist_mbid'] ?? null) ? 'mbid:'.strtolower($credit['artist_mbid']) : null,
            is_string($credit['name'] ?? null) && trim($credit['name']) !== '' ? 'name:'.mb_strtolower(trim($credit['name'])) : null,
        ]));
    }
}
