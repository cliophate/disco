<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogEntityArtwork;
use App\Models\DiscoveryBatch;
use App\Models\DiscoveryPass;
use App\Models\DiscoveryPick;
use App\Music\Discovery\Identity;
use App\Music\ListenBrainz\ListenBrainzClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DiscoveryController extends Controller
{
    public function index(ListenBrainzClient $listenBrainz): JsonResponse
    {
        $sections = [];
        foreach (['new_artists', 'new_releases', 'more'] as $section) {
            $batch = DiscoveryBatch::query()->where('section', $section)->where('status', 'completed')->latest('period')->first();
            $latestAttempt = DiscoveryBatch::query()->where('section', $section)->latest('period')->first();
            $query = DiscoveryPick::query()->whereNull('passed_at')->whereIn('batch_id', DiscoveryBatch::query()->where('section', $section)->where('status', 'completed')->select('id'));
            $current = $batch === null ? collect() : (clone $query)->where('batch_id', $batch->id)->orderBy('position')->get();
            $earlier = $batch === null ? collect() : (clone $query)->where('batch_id', '!=', $batch->id)
                ->where('state', '!=', 'played')->latest('created_at')->get();
            if ($section === 'new_releases') {
                $today = now()->toDateString();
                $current = (clone $query)->where('state', '!=', 'played')
                    ->where('release_date', '>=', now()->subDays(30)->toDateString())
                    ->orderByRaw('CASE WHEN release_date <= ? THEN 0 ELSE 1 END', [$today])
                    ->orderByRaw('CASE WHEN release_date <= ? THEN release_date END DESC', [$today])
                    ->orderBy('release_date')->orderBy('id')->get();
                $earlier = collect();
            }
            $stale = $batch !== null && ($batch->period->lt($section === 'new_releases' ? now()->startOfDay() : now()->startOfWeek())
                || $latestAttempt?->status === 'failed' || ($batch->summary['provider_failures'] ?? 0) > 0);
            $message = ! $listenBrainz->configured() ? 'ListenBrainz not configured. Add your credentials in admin.'
                : ($batch === null ? 'The first batch '.($section === 'new_releases' ? 'runs daily.' : 'runs Monday.')
                    : ($stale ? 'The last refresh is incomplete or overdue. Showing the latest saved picks.'
                        : ($current->isEmpty() ? (($batch->summary['seeds'] ?? 1) === 0 ? 'No artists with MusicBrainz IDs have listening history yet. Import ListenBrainz and enrich your Plex library.' : 'No new albums qualified for this batch.') : null)));
            $sections[] = [
                'key' => $section, 'generated_at' => $batch?->completed_at?->toIso8601String(),
                'stale' => $stale, 'message' => $message,
                'picks' => $this->present($current), 'earlier' => $this->present($earlier),
            ];
        }

        return response()->json(['data' => $sections])->header('Cache-Control', 'private, no-store');
    }

    public function pass(DiscoveryPick $pick): JsonResponse
    {
        DB::transaction(function () use ($pick): void {
            $section = DiscoveryBatch::query()->findOrFail($pick->batch_id)->section;
            $artist = $section === 'new_artists';
            DiscoveryPass::query()->firstOrCreate(['scope' => $artist ? 'artist' : 'album', 'mbid' => $artist ? $pick->artist_mbid : $pick->release_group_mbid], [
                'normalized_name' => $artist ? Identity::name($pick->artist_name) : Identity::album($pick->artist_name, $pick->title),
            ]);
            $query = DiscoveryPick::query()->whereNull('passed_at');
            if ($artist) {
                $query->where('artist_mbid', $pick->artist_mbid);
            } else {
                $query->where('release_group_mbid', $pick->release_group_mbid);
            }
            $query->update(['passed_at' => now()]);
        });

        return response()->json(status: 204);
    }

    private function present($picks): array
    {
        $artworks = CatalogEntityArtwork::query()->whereIn('entity_id', $picks->pluck('entity_id'))
            ->whereIn('status', ['ready', 'stale'])->whereNotNull('content_sha256')->get()->keyBy('entity_id');

        return $picks->map(function (DiscoveryPick $pick) use ($artworks): array {
            $artwork = $artworks->get($pick->entity_id);

            return [
                'id' => $pick->id, 'artist' => $pick->artist_name, 'title' => $pick->title,
                'year' => $pick->release_date ? substr($pick->release_date, 0, 4) : null,
                'type' => $pick->release_type, 'reason' => $pick->reason, 'state' => $pick->state,
                'qobuz_url' => $pick->qobuz_url, 'qobuz_status' => $pick->qobuz_status,
                'release_date' => $pick->release_date,
                'upcoming' => $pick->release_date !== null && $pick->release_date > now()->toDateString(),
                'artwork' => $artwork === null ? null : ['url' => route('api.entity-artwork', ['artwork' => $artwork->id, 'checksum' => $artwork->content_sha256], false), 'width' => $artwork->width, 'height' => $artwork->height],
            ];
        })->all();
    }
}
