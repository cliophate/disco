<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HomeEdition;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RecommendationFeedbackController extends Controller
{
    public function update(Request $request, string $editionId, string $entityId): JsonResponse
    {
        $userId = (string) $request->user()->id;
        $validated = $this->validateFeedback($request);
        $feedback = DB::transaction(function () use ($editionId, $entityId, $userId, $validated): RecommendationFeedback {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:feedback:{$userId}:{$entityId}"]);
            $item = $this->itemForUser($editionId, $entityId, $userId);

            return $this->store($item, $userId, $validated);
        });

        return response()->json(['data' => $this->present($feedback)]);
    }

    public function updateItem(Request $request, string $itemId): JsonResponse
    {
        $userId = (string) $request->user()->id;
        $validated = $this->validateFeedback($request);
        $feedback = DB::transaction(function () use ($itemId, $userId, $validated): RecommendationFeedback {
            $item = RecommendationItem::query()
                ->whereKey($itemId)
                ->whereHas('run', fn ($query) => $query
                    ->where('user_id', $userId)
                    ->where('intent', 'beyond_library'))
                ->firstOrFail();
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:feedback:{$userId}:{$item->entity_id}"]);

            return $this->store($item, $userId, $validated);
        });

        return response()->json(['data' => $this->present($feedback)]);
    }

    public function destroy(Request $request, string $editionId, string $entityId): Response
    {
        $userId = (string) $request->user()->id;
        DB::transaction(function () use ($editionId, $entityId, $userId): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:feedback:{$userId}:{$entityId}"]);
            $item = $this->itemForUser($editionId, $entityId, $userId);
            $this->rejectStaleMutation($item, $userId);
            $deleted = RecommendationFeedback::query()
                ->where('user_id', $userId)
                ->where('entity_id', $item->entity_id)
                ->delete();
            if ($deleted > 0) {
                $this->incrementRevision($userId);
            }
        });

        return response()->noContent();
    }

    public function destroyEntity(Request $request, string $entityId): Response
    {
        $userId = (string) $request->user()->id;
        DB::transaction(function () use ($entityId, $userId): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:feedback:{$userId}:{$entityId}"]);
            $deleted = RecommendationFeedback::query()
                ->where('user_id', $userId)
                ->where('entity_id', $entityId)
                ->delete();
            if ($deleted > 0) {
                $this->incrementRevision($userId);
            }
        });

        return response()->noContent();
    }

    private function itemForUser(string $editionId, string $entityId, string $userId): RecommendationItem
    {
        $edition = HomeEdition::query()
            ->whereKey($editionId)
            ->where('user_id', $userId)
            ->firstOrFail();

        return RecommendationItem::query()
            ->where('run_id', $edition->recommendation_run_id)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }

    private function rejectStaleMutation(RecommendationItem $item, string $userId): void
    {
        $existing = RecommendationFeedback::query()
            ->where('user_id', $userId)
            ->where('entity_id', $item->entity_id)
            ->with('item.run')
            ->first();
        if ($existing?->item?->run?->intent === $item->run->intent
            && $existing->item->run->generated_at?->isAfter($item->run->generated_at)) {
            abort(409, 'Feedback was already changed from a newer recommendation.');
        }
    }

    /** @return array<string, mixed> */
    private function validateFeedback(Request $request): array
    {
        return $request->validate([
            'action' => ['required', Rule::in(['interested', 'not_for_me', 'already_know', 'wrong_match'])],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function store(RecommendationItem $item, string $userId, array $validated): RecommendationFeedback
    {
        $this->rejectStaleMutation($item, $userId);
        $feedback = RecommendationFeedback::query()->firstOrNew([
            'user_id' => $userId,
            'entity_id' => $item->entity_id,
        ]);
        $feedback->fill([
            'recommendation_item_id' => $item->id,
            'action' => $validated['action'],
            'reason' => $validated['reason'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);
        $semanticChange = ! $feedback->exists || $feedback->isDirty(['action', 'reason', 'expires_at']);
        $feedback->save();
        if ($semanticChange) {
            $this->incrementRevision($userId);
        }

        return $feedback;
    }

    private function incrementRevision(string $userId): void
    {
        DB::statement(
            'INSERT INTO discovery.feedback_revisions (user_id, revision, created_at, updated_at) VALUES (?, 1, NOW(), NOW()) ON CONFLICT (user_id) DO UPDATE SET revision = discovery.feedback_revisions.revision + 1, updated_at = EXCLUDED.updated_at',
            [$userId],
        );
    }

    /** @return array<string, mixed> */
    private function present(RecommendationFeedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'recommendation_item_id' => $feedback->recommendation_item_id,
            'entity_id' => $feedback->entity_id,
            'action' => $feedback->action,
            'reason' => $feedback->reason,
            'expires_at' => $feedback->expires_at?->toAtomString(),
            'updated_at' => $feedback->updated_at?->toAtomString(),
        ];
    }
}
