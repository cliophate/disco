<?php

namespace App\Music\Metadata;

use App\Models\ArtistDiscographyGeneration;
use App\Models\ArtistDiscographyItem;
use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\DiscogsEnrichmentState;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Discogs\DiscogsEnricher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class PipelineDiagnosticService
{
    /** @var array<string, array<string, Collection<int, array<string, mixed>>>> */
    private array $rows = [];

    public function __construct(
        private readonly DiscogsEnricher $discogs,
        private readonly CoverArtArchiveIngestor $artwork,
    ) {}

    /** @return array<string, int> */
    public function counts(string $pipeline): array
    {
        return collect($this->statusRows($pipeline))->map(fn (Collection $rows): int => $rows->count())->all();
    }

    public function eligibleCount(string $pipeline): int
    {
        return match ($pipeline) {
            'discogs' => $this->discogs->eligibleEntityIds()->count(),
            'discographies' => $this->discographyArtistIds()->count(),
            'discography-artwork' => $this->currentDiscographyItems()->count(),
            default => 0,
        };
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>,links:array<string,?string>} */
    public function paginate(string $pipeline, string $status, int $page, int $size): array
    {
        $rows = $this->statusRows($pipeline)[$status] ?? collect();
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $size));
        $page = min($page, $lastPage);
        $path = url("/api/v1/metadata/pipelines/{$pipeline}/diagnostics");
        $link = fn (int $target): string => $path.'?'.http_build_query(['status' => $status, 'page' => $target, 'size' => $size]);

        return [
            'data' => $rows->slice(($page - 1) * $size, $size)->values()->all(),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $size, 'total' => $total],
            'links' => [
                'first' => $link(1),
                'prev' => $page > 1 ? $link($page - 1) : null,
                'next' => $page < $lastPage ? $link($page + 1) : null,
                'last' => $link($lastPage),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function retry(string $pipeline, string $id): array
    {
        return match ($pipeline) {
            'discogs' => $this->retryDiscogs($id),
            'discography-artwork' => $this->retryArtwork($id),
            default => $this->notEligible('This pipeline does not support manual recovery.'),
        };
    }

    /** @return array<string, Collection<int, array<string, mixed>>> */
    private function statusRows(string $pipeline): array
    {
        return $this->rows[$pipeline] ??= match ($pipeline) {
            'discogs' => $this->discogsRows(),
            'discographies' => $this->discographyRows(),
            'discography-artwork' => $this->artworkRows(),
            default => [],
        };
    }

    /** @return array<string, Collection<int, array<string, mixed>>> */
    private function discogsRows(): array
    {
        $entities = CatalogEntity::query()->whereIn('id', $this->discogs->eligibleEntityIds())
            ->with(['identifiers' => fn ($query) => $query->where('status', 'active')->whereIn('namespace', ['musicbrainz.artist', 'musicbrainz.release_group'])])
            ->get()->keyBy('id');
        $states = DiscogsEnrichmentState::query()->whereIn('entity_id', $entities->keys())->get()->keyBy('entity_id');
        $resolved = DB::table('source.entity_resolutions as resolutions')
            ->join('source.objects as objects', 'objects.id', '=', 'resolutions.source_object_id')
            ->join('source.providers as providers', 'providers.id', '=', 'objects.provider_id')
            ->where('providers.slug', 'discogs')->where('resolutions.status', 'confirmed')
            ->whereIn('resolutions.entity_id', $entities->keys())
            ->orderByDesc('objects.last_seen_at')
            ->get(['resolutions.entity_id', 'objects.id as object_id', 'objects.canonical_url'])
            ->unique('entity_id')->keyBy('entity_id');
        $freshIds = DB::table('source.entity_resolutions as resolutions')
            ->join('source.objects as objects', 'objects.id', '=', 'resolutions.source_object_id')
            ->join('source.providers as providers', 'providers.id', '=', 'objects.provider_id')
            ->join('source.snapshots as snapshots', 'snapshots.source_object_id', '=', 'objects.id')
            ->where('providers.slug', 'discogs')->where('resolutions.status', 'confirmed')
            ->where('snapshots.http_status', 200)->where('snapshots.expires_at', '>', now())
            ->whereIn('resolutions.entity_id', $entities->keys())
            ->distinct()->pluck('resolutions.entity_id')->flip();
        $rows = collect(['exact', 'fresh', 'stale', 'ambiguous', 'missing', 'conflict', 'failed', 'queued'])
            ->mapWithKeys(fn (string $status): array => [$status => collect()])->all();

        foreach ($entities as $entity) {
            $state = $states[$entity->id] ?? null;
            $object = $resolved[$entity->id] ?? null;
            $mbid = $entity->identifiers->first()?->value;
            $base = [
                'id' => $entity->id,
                'pipeline' => 'discogs',
                'title' => $entity->canonical_name,
                'subject_type' => $entity->kind === 'agent' ? 'artist' : 'album',
                'provider' => 'Discogs',
                'source_basis' => $object?->canonical_url ?? ($mbid === null ? 'No exact MusicBrainz basis' : "MusicBrainz {$mbid}"),
                'record_url' => $entity->kind === 'agent' ? "/artists/{$entity->id}" : "/albums/{$entity->id}",
                'last_attempt_at' => $state?->attempted_at?->toAtomString(),
                'next_retry_at' => $state?->retry_at?->toAtomString(),
            ];
            if ($object !== null) {
                $rows['exact']->push($this->discogsRow($base, 'exact', $state));
                $freshIds->has($entity->id)
                    ? $rows['fresh']->push($this->discogsRow($base, 'fresh', $state))
                    : $rows['stale']->push($this->discogsRow($base, 'stale', $state));
            }
            if ($state !== null && isset($rows[$state->status])) {
                $rows[$state->status]->push($this->discogsRow($base, $state->status, $state));
            } elseif ($state === null) {
                $rows['queued']->push($this->discogsRow($base, 'queued', null));
            }
        }

        return $this->sortRows($rows);
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function discogsRow(array $base, string $status, ?DiscogsEnrichmentState $state): array
    {
        $retryable = in_array($status, ['failed', 'stale'], true)
            && $this->discogs->configured()
            && ($state?->retry_at === null || $state->retry_at->lte(now()));

        return $base + [
            'status' => $status,
            'failure_category' => $state?->error_code ?? match ($status) {
                'ambiguous' => 'multiple_exact_source_relations',
                'missing' => 'source_relation_missing',
                'conflict' => 'identity_conflict',
                'stale' => 'refresh_due',
                'queued' => 'not_attempted',
                default => null,
            },
            'retry_supported' => $retryable,
            'repair_note' => match ($status) {
                'ambiguous', 'conflict' => 'Identity evidence requires review; automatic retry is intentionally disabled.',
                'missing' => 'MusicBrainz currently exposes no exact Discogs relation; the scheduled pipeline will recheck it.',
                'queued' => 'Waiting for the bounded scheduled bootstrap.',
                default => null,
            },
        ];
    }

    /** @return array<string, Collection<int, array<string, mixed>>> */
    private function discographyRows(): array
    {
        $entities = CatalogEntity::query()->whereIn('id', $this->discographyArtistIds())
            ->with(['identifiers' => fn ($query) => $query->where('namespace', 'musicbrainz.artist')->where('status', 'active')])
            ->get()->keyBy('id');
        $generations = ArtistDiscographyGeneration::query()->whereIn('artist_entity_id', $entities->keys())
            ->orderByDesc('generated_at')->get()->unique('artist_entity_id')->keyBy('artist_entity_id');
        $rows = collect(['fresh', 'stale', 'queued'])->mapWithKeys(fn (string $status): array => [$status => collect()])->all();

        foreach ($entities as $entity) {
            $generation = $generations[$entity->id] ?? null;
            $status = $generation === null ? 'queued' : ($generation->expires_at->isFuture() ? 'fresh' : 'stale');
            $rows[$status]->push([
                'id' => $entity->id,
                'pipeline' => 'discographies',
                'status' => $status,
                'title' => $entity->canonical_name,
                'subject_type' => 'artist',
                'provider' => 'MusicBrainz',
                'source_basis' => 'MusicBrainz '.($entity->identifiers->first()?->value ?? 'identity unavailable'),
                'record_url' => "/artists/{$entity->id}",
                'last_attempt_at' => $generation?->generated_at?->toAtomString(),
                'failure_category' => $status === 'stale' ? 'refresh_due' : ($status === 'queued' ? 'not_generated' : null),
                'next_retry_at' => $generation?->expires_at?->toAtomString(),
                'retry_supported' => false,
                'repair_note' => $status === 'fresh' ? null : 'The bounded scheduled bootstrap manages this state.',
            ]);
        }

        return $this->sortRows($rows);
    }

    /** @return array<string, Collection<int, array<string, mixed>>> */
    private function artworkRows(): array
    {
        $items = $this->currentDiscographyItems();
        $releaseIds = $items->pluck('release_group_id');
        $owned = DB::table('library.holdings as holdings')
            ->join('library.plex_item_artworks as artwork', 'artwork.plex_item_id', '=', 'holdings.plex_album_item_id')
            ->whereIn('holdings.release_group_id', $releaseIds)->whereIn('artwork.status', ['ready', 'stale'])
            ->orderByDesc('artwork.last_attempt_at')
            ->get(['holdings.release_group_id', 'artwork.last_attempt_at'])
            ->unique('release_group_id')->keyBy('release_group_id');
        $rows = collect(['ready', 'missing', 'failed', 'queued'])->mapWithKeys(fn (string $status): array => [$status => collect()])->all();

        foreach ($items as $item) {
            $entity = $item->releaseGroup;
            $artwork = $entity->artwork;
            $plex = $owned[$entity->id] ?? null;
            $status = $plex !== null ? 'ready' : match ($artwork?->status) {
                'ready', 'stale' => 'ready',
                'missing' => 'missing',
                'failed' => 'failed',
                default => 'queued',
            };
            $nextRetry = match ($status) {
                'failed' => $artwork?->last_attempt_at?->copy()->addHours((int) config('services.cover_art_archive.retry_ttl_hours', 24)),
                'missing' => $artwork?->last_attempt_at?->copy()->addDays((int) config('services.cover_art_archive.missing_ttl_days', 30)),
                default => null,
            };
            $rows[$status]->push([
                'id' => $entity->id,
                'pipeline' => 'discography-artwork',
                'status' => $status,
                'title' => $entity->canonical_name,
                'subject_type' => 'album',
                'provider' => $plex !== null ? 'Plex' : 'Cover Art Archive',
                'source_basis' => $plex !== null ? 'Exact owned Plex holding' : "MusicBrainz release {$item->official_release_mbid}",
                'record_url' => "/albums/{$entity->id}",
                'last_attempt_at' => $plex?->last_attempt_at ?? $artwork?->last_attempt_at?->toAtomString(),
                'failure_category' => $artwork?->last_error_code ?? match ($status) {
                    'missing' => 'provider_content_missing',
                    'queued' => 'not_attempted',
                    default => null,
                },
                'next_retry_at' => $nextRetry?->toAtomString(),
                'retry_supported' => $status === 'failed' && ($nextRetry === null || $nextRetry->lte(now())),
                'repair_note' => match ($status) {
                    'missing' => 'The exact release currently has no usable archive cover; the scheduled pipeline will recheck it after cooldown.',
                    'queued' => 'Waiting for the bounded scheduled bootstrap.',
                    default => null,
                },
            ]);
        }

        return $this->sortRows($rows);
    }

    /** @return Collection<int, string> */
    public function discographyArtistIds(): Collection
    {
        return DB::table('catalog.entities as entities')
            ->join('catalog.agents', 'catalog.agents.entity_id', '=', 'entities.id')
            ->join('catalog.external_identifiers as identifiers', function ($join): void {
                $join->on('identifiers.entity_id', '=', 'entities.id')
                    ->where('identifiers.namespace', 'musicbrainz.artist')->where('identifiers.status', 'active');
            })
            ->leftJoin('discovery.artist_follows as follows', 'follows.artist_entity_id', '=', 'entities.id')
            ->leftJoin('library.plex_entity_matches as matches', function ($join): void {
                $join->on('matches.entity_id', '=', 'entities.id')->where('matches.match_scope', 'agent')
                    ->whereIn('matches.status', ['confirmed', 'candidate']);
            })
            ->leftJoin('library.plex_items as items', function ($join): void {
                $join->on('items.id', '=', 'matches.plex_item_id')->where('items.item_type', 'artist')->whereNull('items.removed_at');
            })
            ->where('entities.kind', 'agent')->where('entities.status', 'active')
            ->where(fn ($query) => $query->whereNotNull('follows.id')->orWhereNotNull('items.id'))
            ->groupBy('entities.id')->havingRaw('count(distinct identifiers.id) = 1')->pluck('entities.id');
    }

    /** @return Collection<int, ArtistDiscographyItem> */
    private function currentDiscographyItems(): Collection
    {
        $generationIds = ArtistDiscographyGeneration::query()->orderByDesc('generated_at')->get(['id', 'artist_entity_id'])
            ->unique('artist_entity_id')->pluck('id');

        return ArtistDiscographyItem::query()->whereIn('generation_id', $generationIds)
            ->whereHas('releaseGroup', fn ($query) => $query->where('status', 'active')->where('kind', 'release_group'))
            ->with('releaseGroup.artwork')->orderBy('position')->orderBy('generation_id')->get()
            ->unique('release_group_id')->values();
    }

    /** @param array<string, Collection<int, array<string, mixed>>> $rows
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    private function sortRows(array $rows): array
    {
        return collect($rows)->map(fn (Collection $items): Collection => $items
            ->sortBy([['title', 'asc'], ['id', 'asc']], SORT_NATURAL | SORT_FLAG_CASE)->values())->all();
    }

    /** @return array<string, mixed> */
    private function retryDiscogs(string $id): array
    {
        $row = collect($this->statusRows('discogs'))
            ->only(['failed', 'stale'])->flatten(1)->firstWhere('id', $id);
        if ($row === null || ! $row['retry_supported']) {
            return $this->notEligible('Discogs recovery is limited to due failed or stale exact entities.', $row['next_retry_at'] ?? null);
        }
        $entity = CatalogEntity::query()->findOrFail($id);
        try {
            $result = $this->discogs->retryEntity($entity);

            return ['attempted' => true, 'status' => $result['status'], 'failure_category' => null];
        } catch (Throwable) {
            $state = DiscogsEnrichmentState::query()->find($id);

            return ['attempted' => true, 'status' => $state?->status ?? 'failed', 'failure_category' => $state?->error_code];
        }
    }

    /** @return array<string, mixed> */
    private function retryArtwork(string $id): array
    {
        $row = $this->statusRows('discography-artwork')['failed']->firstWhere('id', $id);
        if ($row === null || ! $row['retry_supported']) {
            return $this->notEligible('Artwork recovery is limited to due exact failures.', $row['next_retry_at'] ?? null);
        }
        $item = $this->currentDiscographyItems()->firstWhere('release_group_id', $id);
        if ($item === null) {
            return $this->notEligible('This album is no longer in a current discography generation.');
        }
        try {
            $artwork = $this->artwork->ingest($item->releaseGroup, strtolower($item->official_release_mbid));

            return ['attempted' => true, 'status' => $artwork->status, 'failure_category' => $artwork->last_error_code];
        } catch (Throwable) {
            $artwork = CatalogEntityArtwork::query()->where('entity_id', $id)->first();

            return ['attempted' => true, 'status' => $artwork?->status ?? 'failed', 'failure_category' => $artwork?->last_error_code];
        }
    }

    /** @return array<string, mixed> */
    private function notEligible(string $note, ?string $nextRetryAt = null): array
    {
        return ['attempted' => false, 'status' => 'not_eligible', 'failure_category' => null, 'repair_note' => $note, 'next_retry_at' => $nextRetryAt];
    }
}
