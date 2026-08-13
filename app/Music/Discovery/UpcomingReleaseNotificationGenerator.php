<?php

namespace App\Music\Discovery;

use App\Models\UpcomingReleaseGeneration;
use App\Models\UpcomingReleaseItem;
use App\Models\UpcomingReleaseNotification;
use App\Models\User;
use App\Music\CanonicalEntityResolver;
use App\Music\Notifications\UpcomingNotificationDeliveryPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpcomingReleaseNotificationGenerator
{
    public function __construct(
        private readonly ArtistSeedService $artistSeeds,
        private readonly CanonicalEntityResolver $canonicalEntities,
        private readonly UpcomingNotificationDeliveryPlanner $deliveries,
    ) {}

    /** @return array{generation_id:string,owners:int,items:int,created:int,updated:int} */
    public function generate(?int $maxItems = null): array
    {
        $maxItems ??= (int) config('discovery.upcoming.notification_max_items', 5000);
        if ($maxItems < 1) {
            throw new RuntimeException('The upcoming notification item limit must be at least one.');
        }
        $generation = UpcomingReleaseGeneration::query()->latest('generated_at')->first();
        if ($generation === null) {
            throw new RuntimeException('No upcoming-release generation is available; notifications were not changed.');
        }
        if ($generation->expires_at->isPast()) {
            throw new RuntimeException('The latest upcoming-release generation is stale; notifications were not changed.');
        }
        $itemCount = $generation->items()->count();
        if ($itemCount > $maxItems) {
            throw new RuntimeException("The latest upcoming-release generation has {$itemCount} items, exceeding the {$maxItems}-item safety limit; notifications were not changed.");
        }
        $items = $generation->items()->orderBy('general_rank')->get();
        $totals = ['created' => 0, 'updated' => 0];
        $owners = User::query()->orderBy('id')->get();
        foreach ($owners as $owner) {
            $result = DB::transaction(function () use ($generation, $items, $owner): array {
                DB::statement('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["upcoming-notifications:{$owner->id}"]);

                return $this->generateForOwner($owner, $generation, $items);
            });
            $totals['created'] += $result['created'];
            $totals['updated'] += $result['updated'];
        }

        return [
            'generation_id' => $generation->id,
            'owners' => $owners->count(),
            'items' => $itemCount,
            ...$totals,
        ];
    }

    /** @param Collection<int, UpcomingReleaseItem> $items
     * @return array{created:int,updated:int}
     */
    private function generateForOwner(User $owner, UpcomingReleaseGeneration $generation, Collection $items): array
    {
        $notifications = $this->normalizeNotifications((string) $owner->id);
        $seeds = $this->artistSeeds->exactMbidStates((string) $owner->id);
        $owned = $this->ownedCanonicalGroups();
        $today = CarbonImmutable::today($owner->timezone);
        $seen = [];
        $created = 0;
        $updated = 0;

        foreach ($items as $item) {
            $canonical = $this->canonicalEntities->resolve($item->release_group_id, 'release_group');
            if ($canonical === null || isset($seen[$canonical->id])) {
                continue;
            }
            $seen[$canonical->id] = true;
            $notification = $notifications->get($canonical->id);
            $match = $this->match($item->artist_mbids, $seeds);
            $status = 'active';
            $reason = null;
            if (! $match['explicit'] && ! $match['implicit']) {
                $status = 'resolved';
                $reason = 'no_longer_personalized';
            } elseif (isset($owned[$canonical->id])) {
                $status = 'resolved';
                $reason = 'owned';
            } elseif ($item->release_date->toDateString() < $today->toDateString()) {
                $status = 'resolved';
                $reason = 'released';
            }
            if ($notification === null && $status !== 'active') {
                continue;
            }

            $personalization = $this->personalization($match);
            $attributes = [
                'user_id' => $owner->id,
                'release_group_id' => $canonical->id,
                'source_snapshot_id' => $generation->source_snapshot_id,
                'release_group_mbid' => $item->release_group_mbid,
                'release_mbid' => $item->release_mbid,
                'title' => $item->title,
                'artist_credit_name' => $item->artist_credit_name,
                'artist_mbids' => $item->artist_mbids,
                'release_date' => $item->release_date,
                'primary_type' => $item->primary_type,
                'personalization_type' => $personalization['type'],
                'personalization_reason' => $personalization['reason'],
                'source_provider' => data_get($item->provenance, 'provider', 'listenbrainz'),
                'source_provider_name' => data_get($item->provenance, 'provider_name', 'ListenBrainz'),
                'source_url' => data_get($item->provenance, 'source_url', 'https://api.listenbrainz.org/1/explore/fresh-releases/'),
                'status' => $status,
                'resolution_reason' => $reason,
                'absence_count' => 0,
                'last_seen_generation_id' => $generation->id,
                'last_evaluated_generation_id' => $generation->id,
            ];
            if ($notification === null) {
                $notification = UpcomingReleaseNotification::query()->create($attributes);
                $this->deliveries->enqueue($notification);
                $created++;

                continue;
            }

            $dateChanged = $notification->release_date->toDateString() !== $item->release_date->toDateString();
            $reappeared = $status === 'active' && $notification->status !== 'active';
            $notification->fill($attributes);
            if ($status === 'resolved') {
                $notification->read_at ??= now();
            } elseif ($dateChanged || $reappeared) {
                $notification->read_at = null;
            }
            if ($notification->isDirty()) {
                $notification->save();
                $updated++;
            }
        }

        foreach ($notifications as $groupId => $notification) {
            if (isset($seen[$groupId])) {
                continue;
            }
            if ($this->evaluateMissing($notification, $generation, $seeds, $owned, $today)) {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /** @param array<string, array{explicit:bool,implicit:bool}> $seeds
     * @param  array<string, true>  $owned
     */
    private function evaluateMissing(UpcomingReleaseNotification $notification, UpcomingReleaseGeneration $generation, array $seeds, array $owned, CarbonImmutable $today): bool
    {
        $match = $this->match($notification->artist_mbids, $seeds);
        $sameGeneration = $notification->last_evaluated_generation_id === $generation->id;
        if ($notification->status === 'resolved') {
            return false;
        }
        if (! $match['explicit'] && ! $match['implicit']) {
            $notification->status = 'resolved';
            $notification->resolution_reason = 'no_longer_personalized';
        } elseif (isset($owned[$notification->release_group_id])) {
            $notification->status = 'resolved';
            $notification->resolution_reason = 'owned';
        } elseif ($notification->release_date->toDateString() < $today->toDateString()) {
            $notification->status = 'resolved';
            $notification->resolution_reason = 'released';
        } elseif (! $sameGeneration) {
            $notification->absence_count++;
            if ($notification->absence_count >= (int) config('discovery.upcoming.notification_missing_threshold', 2)) {
                $pivot = CarbonImmutable::parse((string) data_get($generation->coverage, 'pivot_date', $generation->generated_at->toDateString()));
                $reason = $notification->release_date->gt($pivot->addDays($generation->horizon_days)) ? 'outside_horizon' : 'source_absent';
                if ($notification->status !== 'withdrawn') {
                    $notification->read_at = null;
                }
                $notification->status = 'withdrawn';
                $notification->resolution_reason = $reason;
            }
        }
        if ($notification->status === 'resolved' && $notification->read_at === null) {
            $notification->read_at = now();
        }
        $notification->last_evaluated_generation_id = $generation->id;
        if (! $notification->isDirty()) {
            return false;
        }
        $notification->save();

        return true;
    }

    /** @return Collection<string, UpcomingReleaseNotification> */
    private function normalizeNotifications(string $userId): Collection
    {
        $notifications = UpcomingReleaseNotification::query()->where('user_id', $userId)->orderBy('created_at')->get();
        foreach ($notifications as $notification) {
            $canonical = $this->canonicalEntities->resolve($notification->release_group_id, 'release_group');
            if ($canonical === null || $canonical->id === $notification->release_group_id) {
                continue;
            }
            $target = $notifications->first(fn (UpcomingReleaseNotification $candidate): bool => $candidate->release_group_id === $canonical->id);
            if ($target === null) {
                $notification->release_group_id = $canonical->id;
                $notification->save();

                continue;
            }
            $newest = strcmp($notification->last_seen_generation_id, $target->last_seen_generation_id) > 0 ? $notification : $target;
            if ($newest === $notification) {
                $target->status = $notification->status;
                $target->resolution_reason = $notification->resolution_reason;
                $target->absence_count = $notification->absence_count;
                $target->last_seen_generation_id = $notification->last_seen_generation_id;
                $target->last_evaluated_generation_id = $notification->last_evaluated_generation_id;
            }
            if ($target->status === 'resolved') {
                $target->read_at ??= now();
            } elseif ($target->read_at === null || $notification->read_at === null) {
                $target->read_at = null;
            } elseif ($notification->read_at->gt($target->read_at)) {
                $target->read_at = $notification->read_at;
            }
            $target->save();
            $notification->delete();
        }

        return UpcomingReleaseNotification::query()->where('user_id', $userId)->get()->keyBy('release_group_id');
    }

    /** @return array<string, true> */
    private function ownedCanonicalGroups(): array
    {
        $owned = [];
        DB::table('library.holdings as holdings')
            ->join('library.plex_items as albums', 'albums.id', '=', 'holdings.plex_album_item_id')
            ->where('albums.item_type', 'album')->whereNull('albums.removed_at')
            ->distinct()->pluck('holdings.release_group_id')->each(function (string $id) use (&$owned): void {
                $canonical = $this->canonicalEntities->resolve($id, 'release_group');
                if ($canonical !== null) {
                    $owned[$canonical->id] = true;
                }
            });

        return $owned;
    }

    /** @param list<string> $artistMbids
     * @param  array<string, array{explicit:bool,implicit:bool}>  $seeds
     * @return array{explicit:bool,implicit:bool}
     */
    private function match(array $artistMbids, array $seeds): array
    {
        $match = ['explicit' => false, 'implicit' => false];
        foreach ($artistMbids as $mbid) {
            $state = $seeds[strtolower($mbid)] ?? null;
            if ($state !== null) {
                $match['explicit'] = $match['explicit'] || $state['explicit'];
                $match['implicit'] = $match['implicit'] || $state['implicit'];
            }
        }

        return $match;
    }

    /** @param array{explicit:bool,implicit:bool} $match
     * @return array{type:string,reason:string}
     */
    private function personalization(array $match): array
    {
        return match (true) {
            $match['explicit'] && $match['implicit'] => ['type' => 'followed_and_library', 'reason' => 'A followed artist who is also represented in your active library.'],
            $match['explicit'] => ['type' => 'followed', 'reason' => 'An artist you explicitly follow.'],
            $match['implicit'] => ['type' => 'library', 'reason' => 'An artist represented in your active library.'],
            default => ['type' => 'none', 'reason' => 'This release is no longer personalized for you.'],
        };
    }
}
