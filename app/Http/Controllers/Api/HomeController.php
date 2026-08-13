<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Models\HomeEdition;
use App\Models\ListenImportRun;
use App\Models\SourceAccount;
use App\Music\Activity\RecentCollectionActivityService;
use App\Music\Discovery\HomeEditionComposer;
use App\Music\Discovery\HomeProjectionVersion;
use App\Music\Discovery\RecommendationImpressionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request, AlbumListStatePresenter $listStates, HomeEditionComposer $composer, HomeProjectionVersion $projectionVersion, RecommendationImpressionRecorder $impressions, RecentCollectionActivityService $activities): JsonResponse
    {
        $userId = (string) $request->user()->id;
        $listenBrainzAccount = SourceAccount::query()
            ->where('owner_user_id', $userId)
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('slug', 'listenbrainz')->where('enabled', true))
            ->first();
        $calendarDay = now()->toDateString();
        $version = $projectionVersion->current($userId, $calendarDay);
        $edition = HomeEdition::query()
            ->where('user_id', $userId)
            ->where('version_hash', $version)
            ->first();
        if ($edition === null) {
            $edition = $composer->generate($userId, $calendarDay);
        }
        $home = $edition->payload;
        $meta = $home['meta'];
        $home['sections'] = collect($home['sections'] ?? [])
            ->reject(fn (array $section): bool => in_array($section['type'] ?? null, ['recently-heard', 'recently-added'], true))
            ->values()->all();
        $home['recent_artists'] = [];
        $activity = $activities->forUser($userId);
        $home['activity'] = $activity['data'];
        $meta['activity'] = $activity['meta'];
        $latestListenImport = ListenImportRun::query()
            ->when($listenBrainzAccount !== null, fn ($query) => $query->where('source_account_id', $listenBrainzAccount->id))
            ->when($listenBrainzAccount === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
        $meta['last_listenbrainz_import_at'] = $latestListenImport?->completed_at?->toAtomString();
        $meta['edition_id'] = $edition->id;
        $meta['edition_version'] = $edition->version_hash;
        $presentedIds = collect([data_get($home, 'feature.album.id')])
            ->merge(collect(data_get($home, 'sections', []))->pluck('items')->flatten(1)->pluck('album.id'))
            ->filter(fn ($id): bool => is_string($id))
            ->unique()
            ->values()
            ->all();
        $impressions->recordEntities($userId, $edition->recommendation_run_id, $presentedIds, 'home', $edition->id, ['edition_id' => $edition->id]);
        unset($home['meta']);
        $home = $listStates->overlay($home, $userId);

        return response()->json(['data' => $home, 'meta' => $meta]);
    }
}
