<?php

namespace App\Music\Notifications;

use App\Models\UpcomingNotificationDelivery;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class UpcomingNotificationDeliveryService
{
    public function __construct(private readonly GotifyClient $gotify) {}

    /** @return array{requested:int,delivered:int,failed:int,skipped:int} */
    public function deliver(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('The notification delivery limit must be between one and 100.');
        }
        $counts = ['requested' => 0, 'delivered' => 0, 'failed' => 0, 'skipped' => 0];
        while ($counts['requested'] < $limit) {
            $delivery = DB::transaction(function (): ?UpcomingNotificationDelivery {
                $delivery = UpcomingNotificationDelivery::query()
                    ->whereIn('status', ['pending', 'failed'])
                    ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->orderBy('created_at')->orderBy('id')
                    ->with('notification')
                    ->lock('for update skip locked')
                    ->first();
                if ($delivery === null) {
                    return null;
                }
                $delivery->update([
                    'status' => 'sending',
                    'attempt_count' => $delivery->attempt_count + 1,
                    'attempted_at' => now(),
                    'next_attempt_at' => null,
                    'error_code' => null,
                ]);

                return $delivery;
            });
            if ($delivery === null) {
                break;
            }
            $counts['requested']++;
            $notification = $delivery->notification;
            if ($notification === null || $notification->status !== 'active') {
                $delivery->update(['status' => 'skipped', 'skip_reason' => $notification?->status ?? 'notification_missing']);
                $counts['skipped']++;

                continue;
            }

            try {
                $externalId = match ($delivery->channel) {
                    'gotify' => $this->gotify->send($notification),
                    default => throw new RuntimeException('Unsupported notification delivery channel.'),
                };
                $delivery->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'external_id' => $externalId,
                    'skip_reason' => null,
                ]);
                $counts['delivered']++;
            } catch (Throwable $exception) {
                $minutes = min(1440, 15 * (2 ** min(6, max(0, $delivery->attempt_count - 1))));
                $delivery->update([
                    'status' => 'failed',
                    'next_attempt_at' => now()->addMinutes($minutes),
                    'error_code' => $exception instanceof RequestException
                        ? 'http_'.$exception->response->status()
                        : class_basename($exception),
                ]);
                Log::warning('Upcoming notification delivery failed.', [
                    'delivery_id' => $delivery->id,
                    'notification_id' => $delivery->notification_id,
                    'channel' => $delivery->channel,
                    'error_code' => $delivery->error_code,
                ]);
                $counts['failed']++;
            }
        }

        return $counts;
    }
}
