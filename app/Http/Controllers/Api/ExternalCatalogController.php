<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\Discovery\ExternalCatalogService;
use App\Music\MusicBrainz\MusicBrainzCreditEnricher;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExternalCatalogController extends Controller
{
    public function index(Request $request, ExternalCatalogService $catalog): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        return response()->json(['data' => $catalog->search($validated['q'])]);
    }

    public function store(
        string $mbid,
        ExternalCatalogService $catalog,
        MusicBrainzEnricher $musicBrainz,
        MusicBrainzCreditEnricher $creditEnricher,
        AlbumNarrativeEnricher $narratives,
        CoverArtArchiveIngestor $artwork,
    ): JsonResponse {
        $selection = $catalog->materialize($mbid);
        $entity = $selection['entity'];
        $enrichment = ['detail' => 'unavailable', 'credits' => 'unavailable', 'narrative' => 'unavailable', 'artwork' => 'unavailable'];
        $releaseIdentifier = null;
        if ($selection['release_mbid'] !== null) {
            try {
                $releaseIdentifier = ExternalIdentifier::query()
                    ->where('namespace', 'musicbrainz.release')
                    ->where('value', $selection['release_mbid'])
                    ->where('status', 'active')
                    ->firstOrFail();
                $musicBrainz->enrich($releaseIdentifier);
                $enrichment['detail'] = 'ready';
            } catch (Throwable $exception) {
                $enrichment['detail'] = 'failed';
                Log::warning('Selected external album detail enrichment failed.', [
                    'entity_id' => $entity->id,
                    'error_code' => class_basename($exception),
                ]);
            }
        }
        if ($releaseIdentifier !== null) {
            try {
                $creditEnricher->enrich($releaseIdentifier);
                $enrichment['credits'] = 'ready';
            } catch (Throwable $exception) {
                $enrichment['credits'] = 'failed';
                Log::warning('Selected external album credit enrichment failed.', [
                    'entity_id' => $entity->id,
                    'error_code' => class_basename($exception),
                ]);
            }
        }
        try {
            $enrichment['narrative'] = $narratives->enrich($entity) ?? 'missing';
        } catch (Throwable $exception) {
            $enrichment['narrative'] = 'failed';
            Log::warning('Selected external album narrative enrichment failed.', [
                'entity_id' => $entity->id,
                'error_code' => class_basename($exception),
            ]);
        }
        if ($selection['release_mbid'] !== null) {
            try {
                $enrichment['artwork'] = $artwork->ingest($entity, $selection['release_mbid'])->status;
            } catch (Throwable $exception) {
                $enrichment['artwork'] = 'failed';
                Log::warning('Selected external album artwork enrichment failed.', [
                    'entity_id' => $entity->id,
                    'error_code' => class_basename($exception),
                ]);
            }
        }

        return response()->json(['data' => [
            'id' => $entity->id,
            'owned' => Holding::query()
                ->where('release_group_id', $entity->id)
                ->whereHas('plexAlbum', fn ($query) => $query->whereNull('removed_at'))
                ->exists(),
            'enrichment' => $enrichment,
        ]]);
    }
}
