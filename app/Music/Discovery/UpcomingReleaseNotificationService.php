<?php

namespace App\Music\Discovery;

use App\Models\UpcomingReleaseNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UpcomingReleaseNotificationService
{
    public function page(string $userId, string $filter, int $page, int $pageSize): LengthAwarePaginator
    {
        $query = UpcomingReleaseNotification::query()->where('user_id', $userId);
        if ($filter === 'unread') {
            $query->whereIn('status', ['active', 'withdrawn'])->whereNull('read_at');
        } elseif ($filter === 'active') {
            $query->where('status', 'active');
        }
        $lastPage = max(1, (int) ceil((clone $query)->count() / $pageSize));

        return $query
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'withdrawn' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('release_date')->orderBy('id')
            ->paginate(perPage: $pageSize, page: min($page, $lastPage));
    }

    /** @return array<string,mixed> */
    public function present(UpcomingReleaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'release_group_id' => $notification->release_group_id,
            'artist' => $notification->artist_credit_name,
            'title' => $notification->title,
            'release_date' => $notification->release_date->toDateString(),
            'primary_type' => $notification->primary_type,
            'personalization' => [
                'match' => $notification->personalization_type,
                'reason' => $notification->personalization_reason,
            ],
            'source' => [
                'provider' => $notification->source_provider,
                'provider_name' => $notification->source_provider_name,
                'url' => $notification->source_url,
                'snapshot_id' => $notification->source_snapshot_id,
            ],
            'status' => $notification->status,
            'resolution_reason' => $notification->resolution_reason,
            'status_detail' => $this->statusDetail($notification),
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toAtomString(),
            'links' => [
                'album' => "/albums/{$notification->release_group_id}",
                'upcoming' => '/discover/upcoming',
            ],
            'created_at' => $notification->created_at?->toAtomString(),
            'updated_at' => $notification->updated_at?->toAtomString(),
        ];
    }

    private function statusDetail(UpcomingReleaseNotification $notification): string
    {
        return match ($notification->resolution_reason) {
            'source_absent' => "This release was absent from {$notification->absence_count} consecutive fresh {$notification->source_provider_name} generations. It may have moved or been withdrawn.",
            'outside_horizon' => 'This release moved outside the current source horizon and may reappear later.',
            'owned' => 'This release is now represented by an active album in your library.',
            'released' => 'The announced release date has passed.',
            'no_longer_personalized' => 'The artist is no longer followed or represented by a confirmed active library match.',
            default => 'This release is currently announced in the cached upcoming-release feed.',
        };
    }
}
