<?php

namespace App\Music\Discovery;

use App\Models\RecommendationImpression;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;

class RecommendationImpressionRecorder
{
    /** @param list<string> $entityIds
     * @param  array<string, mixed>  $context
     */
    public function recordEntities(string $userId, string $runId, array $entityIds, string $surface, string $contextKey, array $context = []): void
    {
        $run = RecommendationRun::query()->whereKey($runId)->where('user_id', $userId)->first();
        if ($run === null) {
            return;
        }
        $items = RecommendationItem::query()
            ->where('run_id', $run->id)
            ->whereIn('entity_id', array_values(array_unique($entityIds)))
            ->get();
        $this->record($userId, $items, $surface, $contextKey, $context);
    }

    /** @param list<string> $itemIds
     * @param  array<string, mixed>  $context
     */
    public function recordItems(string $userId, array $itemIds, string $surface, string $contextKey, array $context = []): void
    {
        $items = RecommendationItem::query()
            ->whereIn('id', array_values(array_unique($itemIds)))
            ->whereHas('run', fn ($query) => $query->where('user_id', $userId))
            ->get();
        $this->record($userId, $items, $surface, $contextKey, $context);
    }

    private function record(string $userId, mixed $items, string $surface, string $contextKey, array $context): void
    {
        $key = hash('sha256', $contextKey);
        foreach ($items as $item) {
            RecommendationImpression::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'recommendation_item_id' => $item->id,
                    'surface' => $surface,
                    'context_key' => $key,
                ],
                [
                    'entity_id' => $item->entity_id,
                    'context' => $context,
                    'presented_at' => now(),
                ],
            );
        }
    }
}
