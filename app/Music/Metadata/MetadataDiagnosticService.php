<?php

namespace App\Music\Metadata;

use App\Models\CatalogEntity;
use App\Models\EntityNarrative;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Music\Descriptions\NarrativeCoverageReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MetadataDiagnosticService
{
    /** @var array<string, Collection<int, string>> */
    private array $eligibleNarrativeIds = [];

    public function __construct(private readonly NarrativeCoverageReport $narratives) {}

    /** @return array<string, int> */
    public function counts(string $type, string $category): array
    {
        $statuses = match ($category) {
            'identity' => ['ready', 'ambiguous', 'missing'],
            'enrichment' => $type === 'track' ? [] : ['ready', 'missing'],
            'artwork' => $type === 'track' ? [] : ['ready', 'stale', 'failed', 'pending', 'missing'],
            'narrative' => $type === 'track' ? [] : ['ready', 'stale', 'failed', 'pending', 'missing'],
            default => [],
        };

        return collect($statuses)->mapWithKeys(fn (string $status): array => [
            $status => $this->query($type, $category, $status)->count(),
        ])->all();
    }

    /** @return array{data:list<array<string,mixed>>,meta:array<string,int>,links:array<string,?string>} */
    public function paginate(string $type, string $category, string $status, int $page, int $size): array
    {
        $paginator = $this->query($type, $category, $status)
            ->orderByRaw($category === 'narrative' ? 'lower(canonical_name)' : 'lower(title)')
            ->orderBy('id')
            ->paginate(perPage: $size, pageName: 'page', page: $page);
        $paginator->appends(compact('type', 'category', 'status', 'size'));

        $data = $category === 'narrative'
            ? $paginator->getCollection()->map(fn (CatalogEntity $entity): array => $this->narrativeRow($entity, $type, $status))
            : $paginator->getCollection()->map(fn (PlexItem $item): array => $this->plexRow($item, $type, $category, $status));

        return [
            'data' => $data->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'last' => $paginator->url($paginator->lastPage()),
            ],
        ];
    }

    public function retryEligibleAt(string $category, PlexItem|CatalogEntity $subject): ?\DateTimeInterface
    {
        if ($category === 'artwork' && $subject instanceof PlexItem) {
            return $subject->artwork?->last_attempt_at?->copy()->addMinutes(5);
        }
        if ($category !== 'narrative' || ! $subject instanceof CatalogEntity) {
            return null;
        }

        $lastAttempt = $subject->narratives->max('fetched_at');

        return $lastAttempt?->copy()->addDays(7);
    }

    private function query(string $type, string $category, string $status): Builder
    {
        if ($category === 'narrative') {
            $query = CatalogEntity::query()
                ->whereIn('id', $this->eligibleNarrativeIds[$type] ??= $this->narratives->eligibleEntityIds($type))
                ->with(['narratives' => fn ($query) => $query
                    ->where('kind', 'description')
                    ->latest('fetched_at')]);

            return $this->applyNarrativeStatus($query, $status);
        }

        $query = PlexItem::query()
            ->where('item_type', $type)
            ->whereNull('removed_at')
            ->with(['artwork', 'matches.entity.metadata']);

        return match ($category) {
            'identity' => $this->applyIdentityStatus($query, $type, $status),
            'enrichment' => $this->applyEnrichmentStatus($query, $type, $status),
            'artwork' => $this->applyArtworkStatus($query, $status),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function applyIdentityStatus(Builder $query, string $type, string $status): Builder
    {
        $scope = $type === 'album' ? 'release' : match ($type) {
            'artist' => 'agent',
            default => 'recording',
        };
        $confirmed = fn (Builder $query) => $query
            ->where('match_scope', $scope)
            ->where('status', 'confirmed')
            ->where('method', 'external_id');
        $candidate = fn (Builder $query) => $query
            ->where('match_scope', $scope)
            ->where('status', 'candidate');

        return match ($status) {
            'ready' => $query->whereHas('matches', $confirmed),
            'ambiguous' => $query->whereDoesntHave('matches', $confirmed)->whereHas('matches', $candidate),
            'missing' => $query->whereDoesntHave('matches', $confirmed)->whereDoesntHave('matches', $candidate),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function applyEnrichmentStatus(Builder $query, string $type, string $status): Builder
    {
        if ($type === 'track') {
            return $status === 'missing' ? $query : $query->whereRaw('1 = 0');
        }
        $scope = $type === 'album' ? 'release_group' : 'agent';
        $enriched = fn (Builder $query) => $query
            ->where('match_scope', $scope)
            ->where('status', 'confirmed')
            ->whereHas('entity.metadata');

        return match ($status) {
            'ready' => $query->whereHas('matches', $enriched),
            'missing' => $query->whereDoesntHave('matches', $enriched),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function applyArtworkStatus(Builder $query, string $status): Builder
    {
        if ($status === 'missing') {
            return $query->where(fn (Builder $query) => $query
                ->whereDoesntHave('artwork')
                ->orWhereHas('artwork', fn (Builder $artwork) => $artwork->where('status', 'missing')));
        }

        return in_array($status, ['ready', 'stale', 'failed', 'pending'], true)
            ? $query->whereHas('artwork', fn (Builder $artwork) => $artwork->where('status', $status))
            : $query->whereRaw('1 = 0');
    }

    private function applyNarrativeStatus(Builder $query, string $status): Builder
    {
        $description = fn (Builder $query) => $query->where('kind', 'description');
        $stale = fn (Builder $query) => $description($query)->where(function (Builder $query): void {
            $query->where('status', 'stale')
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', 'ready')
                    ->where(fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query->where('provider_slug', 'theaudiodb')->where('fetched_at', '<', now()->subDays(30)))
                        ->orWhere(fn (Builder $query) => $query->where('provider_slug', '<>', 'theaudiodb')->where('fetched_at', '<', now()->subDays(7)))));
        });
        $ready = fn (Builder $query) => $description($query)
            ->where('status', 'ready')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('provider_slug', 'theaudiodb')->where('fetched_at', '>=', now()->subDays(30)))
                ->orWhere(fn (Builder $query) => $query->where('provider_slug', '<>', 'theaudiodb')->where('fetched_at', '>=', now()->subDays(7))));

        return match ($status) {
            'ready' => $query->whereHas('narratives', $ready),
            'stale' => $query->whereHas('narratives', $stale),
            'failed' => $query->whereHas('narratives', fn (Builder $query) => $description($query)->where('status', 'failed')),
            'missing' => $query->whereHas('narratives', fn (Builder $query) => $description($query)->where('status', 'missing')),
            'pending' => $query->whereDoesntHave('narratives', $description),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** @return array<string, mixed> */
    private function plexRow(PlexItem $item, string $type, string $category, string $status): array
    {
        $scope = $type === 'album' ? ($category === 'identity' ? 'release' : 'release_group') : ($type === 'artist' ? 'agent' : 'recording');
        /** @var PlexEntityMatch|null $match */
        $match = $item->matches->first(fn (PlexEntityMatch $match): bool => $match->match_scope === $scope);
        $artwork = $item->artwork;
        $metadata = $match?->entity?->metadata;
        $retrySupported = $category === 'artwork'
            && in_array($status, ['missing', 'failed', 'stale'], true)
            && $item->thumb_key !== null;
        $nextRetry = $retrySupported ? $this->retryEligibleAt($category, $item) : null;

        return [
            'id' => $item->id,
            'type' => $type,
            'category' => $category,
            'status' => $status,
            'title' => $item->title,
            'provider' => match ($category) {
                'identity' => $match?->method,
                'enrichment' => $metadata?->source_provider ?? 'musicbrainz',
                default => 'plex',
            },
            'last_attempt_at' => match ($category) {
                'identity' => $match?->updated_at?->toAtomString(),
                'enrichment' => $metadata?->enriched_at?->toAtomString(),
                default => $artwork?->last_attempt_at?->toAtomString(),
            },
            'failure_category' => match ($category) {
                'identity' => $status === 'ambiguous' ? 'candidate_identity' : ($status === 'missing' ? 'no_confirmed_identity' : null),
                'enrichment' => $status === 'missing' ? ($match === null ? 'identity_required' : 'metadata_missing') : null,
                default => $artwork?->last_error_code ?? ($item->thumb_key === null ? 'source_artwork_missing' : null),
            },
            'next_retry_at' => $nextRetry?->format(DATE_ATOM),
            'retry_supported' => $retrySupported && ($nextRetry === null || $nextRetry <= now()),
            'repair_note' => $category === 'identity'
                ? 'Manual identity overrides are not supported; the next exact catalog sync may resolve this item.'
                : ($category === 'enrichment' ? 'Metadata enrichment is managed by the bounded catalog pipeline.' : null),
        ];
    }

    /** @return array<string, mixed> */
    private function narrativeRow(CatalogEntity $entity, string $type, string $status): array
    {
        $records = $entity->narratives;
        $matching = $records->filter(fn (EntityNarrative $record): bool => $this->narratives->effectiveStatus($record) === $status);
        $lastAttempt = $records->max('fetched_at');
        $retryable = in_array($status, ['missing', 'failed', 'stale', 'pending'], true);
        $nextRetry = $retryable ? $lastAttempt?->copy()->addDays(7) : null;

        return [
            'id' => $entity->id,
            'type' => $type,
            'category' => 'narrative',
            'status' => $status,
            'title' => $entity->canonical_name,
            'provider' => $matching->pluck('provider_slug')->unique()->sort()->implode(', ') ?: null,
            'last_attempt_at' => $lastAttempt?->toAtomString(),
            'failure_category' => match ($status) {
                'failed' => 'provider_failure',
                'missing' => 'provider_content_missing',
                'stale' => 'refresh_due',
                'pending' => 'not_attempted',
                default => null,
            },
            'next_retry_at' => $nextRetry?->toAtomString(),
            'retry_supported' => $retryable && ($nextRetry === null || $nextRetry <= now()),
            'repair_note' => null,
        ];
    }
}
