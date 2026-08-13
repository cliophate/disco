<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogEntity;
use App\Models\PlexItem;
use App\Music\Artwork\PlexArtworkIngestor;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\Descriptions\ArtistBiographyEnricher;
use App\Music\Descriptions\NarrativeCoverageReport;
use App\Music\Metadata\MetadataDiagnosticService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;

class RetryMetadataDiagnosticController extends Controller
{
    public function __invoke(
        string $category,
        string $id,
        MetadataDiagnosticService $diagnostics,
        NarrativeCoverageReport $coverage,
        PlexArtworkIngestor $artworkIngestor,
        AlbumNarrativeEnricher $albumEnricher,
        ArtistBiographyEnricher $artistEnricher,
    ): JsonResponse {
        try {
            return match ($category) {
                'artwork' => $this->retryArtwork($id, $diagnostics, $artworkIngestor),
                'narrative' => $this->retryNarrative($id, $diagnostics, $coverage, $albumEnricher, $artistEnricher),
                default => response()->json([
                    'code' => 'unsupported_repair',
                    'message' => 'This metadata category does not support manual repair.',
                ], 422),
            };
        } catch (LockTimeoutException) {
            return response()->json([
                'code' => 'retry_in_progress',
                'message' => 'A retry for this entity is already in progress.',
            ], 409);
        }
    }

    private function retryArtwork(string $id, MetadataDiagnosticService $diagnostics, PlexArtworkIngestor $ingestor): JsonResponse
    {
        $item = PlexItem::query()
            ->whereKey($id)
            ->whereIn('item_type', ['artist', 'album'])
            ->whereNull('removed_at')
            ->with('artwork')
            ->firstOrFail();
        if ($item->thumb_key === null || in_array($item->artwork?->status, ['ready', 'pending'], true)) {
            return $this->notEligible();
        }
        $nextRetry = $diagnostics->retryEligibleAt('artwork', $item);
        if ($nextRetry !== null && $nextRetry > now()) {
            return $this->notEligible($nextRetry->format(DATE_ATOM));
        }

        $artwork = $ingestor->ingest($item);

        return response()->json(['data' => [
            'attempted' => true,
            'status' => $artwork->status,
            'last_attempt_at' => $artwork->last_attempt_at?->toAtomString(),
            'failure_category' => $artwork->last_error_code,
        ]]);
    }

    private function retryNarrative(
        string $id,
        MetadataDiagnosticService $diagnostics,
        NarrativeCoverageReport $coverage,
        AlbumNarrativeEnricher $albumEnricher,
        ArtistBiographyEnricher $artistEnricher,
    ): JsonResponse {
        $entity = CatalogEntity::query()
            ->whereKey($id)
            ->whereIn('kind', ['release_group', 'agent'])
            ->with('narratives')
            ->firstOrFail();
        $type = $entity->kind === 'agent' ? 'artist' : 'album';
        if (! $coverage->eligibleEntityIds($type)->contains($entity->id)) {
            return $this->notEligible();
        }
        $retryable = $entity->narratives->isEmpty()
            || $entity->narratives->contains(fn ($record): bool => in_array($coverage->effectiveStatus($record), ['missing', 'failed', 'stale'], true));
        if (! $retryable) {
            return $this->notEligible();
        }
        $nextRetry = $diagnostics->retryEligibleAt('narrative', $entity);
        if ($nextRetry !== null && $nextRetry > now()) {
            return $this->notEligible($nextRetry->format(DATE_ATOM));
        }

        $result = $entity->kind === 'agent'
            ? $artistEnricher->retryEntity($entity)
            : $albumEnricher->retryEntity($entity);

        return response()->json(['data' => $result]);
    }

    private function notEligible(?string $nextRetryAt = null): JsonResponse
    {
        return response()->json([
            'code' => 'retry_not_eligible',
            'message' => 'This record is not currently eligible for a safe retry.',
            'next_retry_at' => $nextRetryAt,
        ], 409);
    }
}
