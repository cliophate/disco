<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListenImportRun;
use App\Models\ListeningEvent;
use App\Models\PlexItem;
use App\Models\PlexSyncRun;
use App\Models\SourceAccount;
use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Metadata\MetadataDiagnosticService;
use App\Music\Metadata\PipelineStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MetadataCoverageController extends Controller
{
    public function __invoke(
        MetadataDiagnosticService $diagnostics,
        PipelineStatusService $pipelines,
        ProviderCredentialResolver $credentials,
    ): JsonResponse {
        $entities = [];
        $compatibility = [];
        foreach (['artist', 'album', 'track'] as $type) {
            $scope = match ($type) {
                'artist' => 'agent',
                'album' => 'release_group',
                'track' => 'recording',
            };
            $base = PlexItem::query()->where('item_type', $type)->whereNull('removed_at');
            $total = (clone $base)->count();
            $identityScope = $type === 'album' ? 'release' : $scope;
            $identified = (clone $base)->whereHas('matches', fn ($query) => $query
                ->where('match_scope', $identityScope)
                ->where('status', 'confirmed')
                ->where('method', 'external_id'))
                ->count();
            $enriched = in_array($type, ['artist', 'album'], true)
                ? (clone $base)->whereHas('matches', fn ($query) => $query
                    ->where('match_scope', $scope)
                    ->where('status', 'confirmed')
                    ->whereHas('entity.metadata'))->count()
                : 0;
            $artwork = in_array($type, ['artist', 'album'], true)
                ? (clone $base)->whereHas('artwork', fn ($query) => $query->whereIn('status', ['ready', 'stale']))->count()
                : 0;
            $entities[] = [
                'type' => $type,
                'total' => $total,
                'identified' => $identified,
                'missing_identity' => $total - $identified,
                'enriched' => $enriched,
                'artwork_ready' => $artwork,
                'identity_percentage' => $total === 0 ? 0 : round($identified / $total * 100, 1),
                'statuses' => [
                    'identity' => $diagnostics->counts($type, 'identity'),
                    'enrichment' => $diagnostics->counts($type, 'enrichment'),
                    'artwork' => $diagnostics->counts($type, 'artwork'),
                    'narrative' => $diagnostics->counts($type, 'narrative'),
                ],
            ];
            $compatibility["{$type}s_total"] = $total;
            $compatibility["{$type}s_complete"] = $identified;
            $compatibility["{$type}s_missing_mbid"] = $total - $identified;
        }

        $listenBrainzAccount = SourceAccount::query()
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('slug', 'listenbrainz'))
            ->first();
        $listenStats = DB::table('activity.listening_event_matches as matches')
            ->join('activity.listening_events as events', 'events.id', '=', 'matches.listening_event_id')
            ->when($listenBrainzAccount !== null, fn ($query) => $query->where('events.source_account_id', $listenBrainzAccount->id))
            ->when($listenBrainzAccount === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('matches.source_present', true)
            ->selectRaw("COUNT(*) AS current_listens,
                COUNT(*) FILTER (WHERE matches.recording_entity_id IS NOT NULL) AS recording_matched,
                COUNT(*) FILTER (WHERE matches.status = 'matched' AND matches.release_group_entity_id IS NOT NULL) AS album_matched,
                COUNT(*) FILTER (WHERE matches.status = 'unmatched') AS unmatched,
                COUNT(*) FILTER (WHERE matches.status = 'conflict') AS conflicts,
                MAX(events.listened_at) AS latest_listened_at")
            ->first();
        $activeListens = (int) ($listenStats?->current_listens ?? 0);
        $albumMatched = (int) ($listenStats?->album_matched ?? 0);
        $listenRuns = ListenImportRun::query()
            ->when($listenBrainzAccount !== null, fn ($query) => $query->where('source_account_id', $listenBrainzAccount->id));
        $latestListenRun = (clone $listenRuns)->latest('started_at')->first();
        $latestSuccessfulListenRun = (clone $listenRuns)->where('status', 'completed')->latest('completed_at')->first();

        return response()->json(['data' => [
            ...$compatibility,
            'entities' => $entities,
            'overall' => [
                'total' => collect($entities)->sum('total'),
                'identified' => collect($entities)->sum('identified'),
            ],
            'pipelines' => $pipelines->summarize(),
            'last_plex_sync_at' => PlexSyncRun::query()->where('status', 'completed')->max('completed_at'),
            'listenbrainz' => [
                'enabled' => filled(config('services.listenbrainz.username'))
                    && $credentials->resolve('listenbrainz')['configured'],
                'username' => config('services.listenbrainz.username'),
                'observations' => ListeningEvent::query()
                    ->when($listenBrainzAccount !== null, fn ($query) => $query->where('source_account_id', $listenBrainzAccount->id))
                    ->when($listenBrainzAccount === null, fn ($query) => $query->whereRaw('1 = 0'))
                    ->count(),
                'current_listens' => $activeListens,
                'recording_matched' => (int) ($listenStats?->recording_matched ?? 0),
                'album_matched' => $albumMatched,
                'unmatched' => (int) ($listenStats?->unmatched ?? 0),
                'conflicts' => (int) ($listenStats?->conflicts ?? 0),
                'album_match_percentage' => $activeListens === 0 ? 0 : round($albumMatched / $activeListens * 100, 1),
                'latest_listened_at' => $listenStats?->latest_listened_at,
                'last_import_at' => $latestSuccessfulListenRun?->completed_at?->toAtomString(),
                'last_import_status' => $latestListenRun?->status,
                'last_full_import_at' => data_get($listenBrainzAccount?->cursor, 'last_full_completed_at'),
            ],
        ]]);
    }
}
