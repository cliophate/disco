<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneRecommendationHistory extends Command
{
    protected $signature = 'disco:recommendation-prune {--days=180 : Retention window in days}';

    protected $description = 'Prune expired recommendation history while retaining the latest Home edition';

    public function handle(): int
    {
        $days = max(30, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        [$editions, $runs, $feedback, $snapshots] = DB::transaction(function () use ($cutoff): array {
            $currentEditionIds = DB::table('discovery.home_editions')
                ->selectRaw('DISTINCT ON (user_id) id')
                ->orderBy('user_id')
                ->orderByDesc('generated_at')
                ->pluck('id');
            $staleEditionRunIds = DB::table('discovery.home_editions')
                ->where('generated_at', '<', $cutoff)
                ->whereNotIn('id', $currentEditionIds)
                ->pluck('recommendation_run_id');
            $editions = DB::table('discovery.home_editions')
                ->where('generated_at', '<', $cutoff)
                ->whereNotIn('id', $currentEditionIds)
                ->delete();
            $latestRunIds = DB::table('discovery.recommendation_runs')
                ->selectRaw('DISTINCT ON (user_id, intent) id')
                ->orderBy('user_id')
                ->orderBy('intent')
                ->orderByDesc('generated_at')
                ->pluck('id');
            $latestBeyondRunIds = DB::table('discovery.recommendation_runs as runs')
                ->selectRaw('DISTINCT ON (runs.user_id) runs.id')
                ->where('runs.intent', 'beyond_library')
                ->where('runs.status', 'completed')
                ->whereExists(fn ($items) => $items
                    ->selectRaw('1')
                    ->from('discovery.recommendation_items')
                    ->whereColumn('recommendation_items.run_id', 'runs.id'))
                ->orderBy('runs.user_id')
                ->orderByDesc('runs.generated_at')
                ->pluck('id');
            $protectedRunIds = $latestRunIds->merge($latestBeyondRunIds)->unique();
            $runs = DB::table('discovery.recommendation_runs')
                ->where(function ($query) use ($cutoff, $staleEditionRunIds): void {
                    $query->whereIn('id', $staleEditionRunIds)
                        ->orWhere(fn ($or) => $or->where('generated_at', '<', $cutoff)
                            ->whereNotExists(fn ($edition) => $edition
                                ->selectRaw('1')
                                ->from('discovery.home_editions')
                                ->whereColumn('home_editions.recommendation_run_id', 'recommendation_runs.id')));
                })
                ->whereNotIn('id', $protectedRunIds)
                ->delete();
            $feedback = DB::table('discovery.recommendation_feedback')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->delete();
            $referencedSnapshotIds = DB::table('discovery.recommendation_runs')
                ->whereRaw("input->>'source_snapshot_id' IS NOT NULL")
                ->selectRaw("input->>'source_snapshot_id' AS source_snapshot_id")
                ->pluck('source_snapshot_id');
            $snapshots = DB::table('source.snapshots')
                ->join('source.objects', 'objects.id', '=', 'snapshots.source_object_id')
                ->join('source.providers', 'providers.id', '=', 'objects.provider_id')
                ->where('providers.slug', 'listenbrainz')
                ->where('objects.object_type', 'cf_recording_recommendations')
                ->where('snapshots.expires_at', '<', $cutoff)
                ->whereNotIn('snapshots.id', $referencedSnapshotIds)
                ->delete();

            return [$editions, $runs, $feedback, $snapshots];
        });

        $this->table(['Editions', 'Runs', 'Expired feedback', 'Provider snapshots'], [[$editions, $runs, $feedback, $snapshots]]);

        return self::SUCCESS;
    }
}
