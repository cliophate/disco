<?php

namespace App\Music\Discovery;

use App\Models\AlbumListItem;
use App\Models\ArtistFollow;
use App\Models\CatalogEntityArtwork;
use App\Models\EditorialItem;
use App\Models\EntityMetadata;
use App\Models\ListenImportRun;
use App\Models\PlexItemArtwork;
use App\Models\PlexSyncRun;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationRun;
use App\Models\SourceAccount;
use App\Music\Personal\AlbumListService;
use Illuminate\Support\Facades\DB;

class HomeProjectionVersion
{
    public function __construct(private readonly AlbumListService $albumLists) {}

    public function current(string $userId, ?string $calendarDay = null): string
    {
        $this->albumLists->normalize($userId);
        $calendarDay ??= now()->toDateString();
        $listenBrainzAccount = SourceAccount::query()
            ->where('owner_user_id', $userId)
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('slug', 'listenbrainz')->where('enabled', true))
            ->first();
        $feedbackState = RecommendationFeedback::query()
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('entity_id')
            ->get(['entity_id', 'action', 'reason', 'expires_at'])
            ->map(fn (RecommendationFeedback $feedback): array => [
                'entity_id' => $feedback->entity_id,
                'action' => $feedback->action,
                'reason' => $feedback->reason,
                'expires_at' => $feedback->expires_at?->toAtomString(),
            ])->all();

        return hash('sha256', json_encode([
            HomeDiscoveryService::ALGORITHM,
            HomeDiscoveryService::configuration(),
            $calendarDay,
            PlexSyncRun::query()->where('status', 'completed')->max('completed_at'),
            EntityMetadata::query()->max('updated_at'),
            PlexItemArtwork::query()->max('updated_at'),
            CatalogEntityArtwork::query()->max('updated_at'),
            EditorialItem::query()->where('expires_at', '>', now())->max('updated_at'),
            $listenBrainzAccount?->id,
            $listenBrainzAccount !== null && ListenImportRun::query()
                ->where('source_account_id', $listenBrainzAccount->id)
                ->where('status', 'completed')
                ->exists(),
            (int) data_get($listenBrainzAccount?->cursor, 'activity_revision', 0),
            (int) DB::table('discovery.feedback_revisions')->where('user_id', $userId)->value('revision'),
            $feedbackState,
            ArtistFollow::query()->where('user_id', $userId)->orderBy('artist_entity_id')->pluck('artist_entity_id')->all(),
            AlbumListItem::query()->where('user_id', $userId)->whereIn('status', ['want_to_listen', 'listened'])
                ->orderBy('release_group_entity_id')->get(['release_group_entity_id', 'status', 'state_changed_at'])
                ->map(fn (AlbumListItem $item): array => ['id' => $item->release_group_entity_id, 'status' => $item->status, 'changed' => $item->state_changed_at?->toAtomString()])->all(),
            RecommendationRun::query()
                ->where('user_id', $userId)
                ->where('intent', 'beyond_library')
                ->where('status', 'completed')
                ->whereHas('items')
                ->latest('generated_at')
                ->value('id'),
        ], JSON_THROW_ON_ERROR));
    }
}
