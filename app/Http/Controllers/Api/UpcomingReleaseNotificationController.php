<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UpcomingReleaseNotification;
use App\Music\Discovery\UpcomingReleaseNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpcomingReleaseNotificationController extends Controller
{
    public function index(Request $request, UpcomingReleaseNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['sometimes', 'in:all,unread,active'],
            'page' => ['sometimes', 'array:number,size'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $filter = $validated['filter'] ?? 'all';
        $page = $notifications->page(
            (string) $request->user()->id,
            $filter,
            (int) data_get($validated, 'page.number', 1),
            (int) data_get($validated, 'page.size', 25),
        );
        $currentPage = $page->currentPage();
        $pageUrl = function (int $target) use ($filter, $page, $request): string {
            $query = ['filter' => $filter];
            data_set($query, 'page.number', $target);
            data_set($query, 'page.size', $page->perPage());

            return $request->url().'?'.http_build_query($query);
        };

        return response()->json([
            'data' => $page->getCollection()->map(fn (UpcomingReleaseNotification $notification): array => $notifications->present($notification))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'filter' => $filter,
            ],
            'links' => [
                'first' => $pageUrl(1),
                'prev' => $currentPage > 1 ? $pageUrl($currentPage - 1) : null,
                'next' => $currentPage < $page->lastPage() ? $pageUrl($currentPage + 1) : null,
                'last' => $pageUrl($page->lastPage()),
            ],
        ]);
    }

    public function update(Request $request, string $id, UpcomingReleaseNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate(['read' => ['required', 'boolean']]);
        $notification = UpcomingReleaseNotification::query()
            ->where('user_id', $request->user()->id)->findOrFail($id);
        if ($notification->status === 'resolved' && ! $validated['read']) {
            abort(422, 'Resolved notifications cannot be marked unread.');
        }
        if ($validated['read'] && $notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        } elseif (! $validated['read'] && $notification->read_at !== null) {
            $notification->update(['read_at' => null]);
        }

        return response()->json(['data' => $notifications->present($notification->refresh())]);
    }
}
