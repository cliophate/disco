<?php

namespace App\Music\Plex;

use App\Models\PlexMediaPart;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PlexPlaybackSessionService
{
    private const SESSION_TTL_SECONDS = 21600;

    private const TIMELINE_WRITE_LIMIT = 500;

    private const RANGE_REQUEST_LIMIT = 200;

    private const STREAM_DEADLINE_SECONDS = 21500;

    private const STREAM_LEASE_SECONDS = 21900;

    public function __construct(private readonly PlexClient $client) {}

    /** @return array{id:string,stream_url:string,expires_at:string} */
    public function create(User $user, string $mediaPartId): array
    {
        $part = $this->authorizedPart($mediaPartId);
        [$sessionSlot, $sessionLockOwner] = $this->acquireSessionSlot($user);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = now()->addSeconds(self::SESSION_TTL_SECONDS);
        $stored = Cache::put($this->key($token), [
            'user_id' => (string) $user->id,
            'media_part_id' => $part->id,
            'media_version' => $part->media_version,
            'plex_client_identifier' => 'disco-'.substr(hash('sha256', $token), 0, 32),
            'size_bytes' => $part->size_bytes,
            'state' => 'stopped',
            'position_ms' => 0,
            'accumulated_ms' => 0,
            'last_event_at_ms' => $this->nowMs(),
            'last_timeline_at_ms' => null,
            'timeline_writes' => 0,
            'stream_opened_at_ms' => null,
            'streamed_ranges' => [],
            'streamed_bytes' => 0,
            'egress_bytes' => 0,
            'range_requests' => 0,
            'scrobble_attempted' => false,
            'scrobbled' => false,
            'session_slot' => $sessionSlot,
            'session_lock_owner' => $sessionLockOwner,
            'expires_at' => $expiresAt->toAtomString(),
        ], $expiresAt);
        if (! $stored) {
            Cache::restoreLock($this->sessionSlotKey($user, $sessionSlot), $sessionLockOwner)->release();
            throw new RuntimeException('Playback session could not be stored.');
        }

        return [
            'id' => $token,
            'stream_url' => route('api.playback.stream', ['session' => $token], false),
            'expires_at' => $expiresAt->toAtomString(),
        ];
    }

    /** @return array{state:string,position_ms:int,scrobbled:bool} */
    public function update(User $user, string $token, string $state, int $positionMs): array
    {
        $lock = $this->controlLock($token);
        try {
            $session = $this->session($token, $user);
            if ($session['state'] === 'ended') {
                if ($state !== 'ended') {
                    throw new ConflictHttpException('Ended playback sessions cannot be resumed.');
                }

                return ['state' => 'ended', 'position_ms' => (int) $session['position_ms'], 'scrobbled' => (bool) $session['scrobbled']];
            }
            if (! $this->validTransition((string) $session['state'], $state)) {
                throw new ConflictHttpException('Invalid playback state transition.');
            }
            if (! is_int($session['stream_opened_at_ms'])) {
                throw new ConflictHttpException('Playback has not opened its authorized media stream.');
            }

            $part = $this->authorizedPart($session['media_part_id'], $session['media_version']);
            $duration = $this->duration($part);
            $position = min(max(0, $positionMs), $duration);
            $now = $this->nowMs();
            $elapsed = max(0, $now - (int) $session['last_event_at_ms']);
            $positionDelta = max(0, $position - (int) $session['position_ms']);
            if ($session['state'] === 'playing') {
                $session['accumulated_ms'] = min(
                    $duration,
                    (int) $session['accumulated_ms'] + min($positionDelta, $elapsed),
                );
            }

            $lastTimeline = is_int($session['last_timeline_at_ms']) ? $session['last_timeline_at_ms'] : null;
            $stateChanged = $state !== $session['state'];
            $minimumInterval = $stateChanged ? 5000 : 10000;
            $writeTimeline = (int) $session['timeline_writes'] < self::TIMELINE_WRITE_LIMIT
                && ($lastTimeline === null || $now - $lastTimeline >= $minimumInterval);
            $shouldScrobble = $state === 'ended' && ! $session['scrobble_attempted']
                && $position >= max(0, $duration - 10000)
                && (int) $session['accumulated_ms'] >= (int) floor($duration * 0.5)
                && (int) $session['streamed_bytes'] >= (int) floor((int) $session['size_bytes'] * 0.5);

            $session['state'] = $state;
            $session['position_ms'] = $position;
            $session['last_event_at_ms'] = $now;
            if ($writeTimeline) {
                $session['last_timeline_at_ms'] = $now;
                $session['timeline_writes'] = (int) $session['timeline_writes'] + 1;
            }
            if ($shouldScrobble) {
                $session['scrobble_attempted'] = true;
            }
            $this->store($token, $session);

            if ($writeTimeline) {
                $plexState = $state === 'playing' ? 'playing' : ($state === 'paused' ? 'paused' : 'stopped');
                $this->client->playbackTimeline($part->item->rating_key, $plexState, $position, $duration, $session['plex_client_identifier']);
            }
            if ($shouldScrobble) {
                $this->client->scrobble($part->item->rating_key, $session['plex_client_identifier']);
                $session['scrobbled'] = true;
                $this->store($token, $session);
            }

            return ['state' => $state, 'position_ms' => $position, 'scrobbled' => (bool) $session['scrobbled']];
        } finally {
            $lock->release();
        }
    }

    public function destroy(User $user, string $token): void
    {
        $lock = $this->controlLock($token);
        $session = null;
        $authenticated = false;
        try {
            $session = $this->session($token, $user);
            $authenticated = true;
            $part = $this->authorizedPart($session['media_part_id'], $session['media_version']);
            if (! in_array($session['state'], ['stopped', 'ended'], true)
                && (int) $session['timeline_writes'] < self::TIMELINE_WRITE_LIMIT) {
                $duration = $this->duration($part);
                $session['state'] = 'stopped';
                $session['timeline_writes'] = (int) $session['timeline_writes'] + 1;
                $session['last_timeline_at_ms'] = $this->nowMs();
                $this->store($token, $session);
                $this->client->playbackTimeline($part->item->rating_key, 'stopped', min((int) $session['position_ms'], $duration), $duration, $session['plex_client_identifier']);
            }
        } finally {
            if ($authenticated) {
                Cache::forget($this->key($token));
                $this->releaseSessionSlot($user, $session);
            }
            $lock->release();
        }
    }

    public function part(User $user, string $token): PlexMediaPart
    {
        $session = $this->session($token, $user);

        return $this->authorizedPart($session['media_part_id'], $session['media_version']);
    }

    public function markStreamOpened(User $user, string $token): void
    {
        $lock = $this->controlLock($token);
        try {
            $session = $this->session($token, $user);
            if (! is_int($session['stream_opened_at_ms'])) {
                $this->authorizedPart($session['media_part_id'], $session['media_version']);
                $session['stream_opened_at_ms'] = $this->nowMs();
                $this->store($token, $session);
            }
        } finally {
            $lock->release();
        }
    }

    public function reserveStreamRequest(User $user, string $token): void
    {
        $lock = $this->controlLock($token);
        try {
            $session = $this->session($token, $user);
            if ((int) $session['range_requests'] >= self::RANGE_REQUEST_LIMIT
                || (int) $session['egress_bytes'] >= (int) $session['size_bytes'] * 4) {
                throw new TooManyRequestsHttpException(30, 'Playback range request limit reached.');
            }
            $session['range_requests'] = (int) $session['range_requests'] + 1;
            $this->store($token, $session);
        } finally {
            $lock->release();
        }
    }

    public function markStreamedRange(User $user, string $token, int $start, int $end): void
    {
        $lock = $this->controlLock($token);
        try {
            $session = $this->session($token, $user);
            $size = (int) $session['size_bytes'];
            if ($start < 0 || $end < $start || $end >= $size) {
                return;
            }
            $ranges = [...$session['streamed_ranges'], [$start, $end]];
            usort($ranges, fn (array $left, array $right): int => $left[0] <=> $right[0]);
            $merged = [];
            foreach ($ranges as $range) {
                $last = array_key_last($merged);
                if ($last !== null && $range[0] <= $merged[$last][1] + 1) {
                    $merged[$last][1] = max($merged[$last][1], $range[1]);
                } else {
                    $merged[] = [(int) $range[0], (int) $range[1]];
                }
            }
            if (count($merged) > 64) {
                return;
            }
            $session['streamed_ranges'] = $merged;
            $session['streamed_bytes'] = array_sum(array_map(fn (array $range): int => $range[1] - $range[0] + 1, $merged));
            $session['egress_bytes'] = min((int) $session['size_bytes'] * 5, (int) $session['egress_bytes'] + $end - $start + 1);
            $this->store($token, $session);
        } finally {
            $lock->release();
        }
    }

    public function acquireStreamLease(User $user): PlexPlaybackStreamLease
    {
        $slots = min(2, max(1, (int) config('services.plex.max_concurrent_streams', 2)));
        foreach (range(1, $slots) as $globalSlot) {
            $global = Cache::lock("disco:playback-stream:global:{$globalSlot}", self::STREAM_LEASE_SECONDS);
            if (! $global->get()) {
                continue;
            }
            foreach (range(1, $slots) as $userSlot) {
                $userLock = Cache::lock("disco:playback-stream:user:{$user->id}:{$userSlot}", self::STREAM_LEASE_SECONDS);
                if ($userLock->get()) {
                    return new PlexPlaybackStreamLease([$global, $userLock], microtime(true) + self::STREAM_DEADLINE_SECONDS);
                }
            }
            $global->release();
        }

        throw new TooManyRequestsHttpException(30, 'Too many concurrent playback streams.');
    }

    /** @return array<string, mixed> */
    private function session(string $token, User $user): array
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
            throw new NotFoundHttpException('Playback session not found.');
        }
        $session = Cache::get($this->key($token));
        if (! is_array($session)
            || ! isset($session['user_id'], $session['media_part_id'], $session['media_version'], $session['plex_client_identifier'], $session['expires_at'])
            || ! hash_equals((string) $user->id, (string) $session['user_id'])
            || now()->gte($session['expires_at'])) {
            throw new NotFoundHttpException('Playback session not found.');
        }

        return $session;
    }

    private function authorizedPart(string $mediaPartId, ?string $mediaVersion = null): PlexMediaPart
    {
        $part = PlexMediaPart::query()->with('item.library.server')->find($mediaPartId);
        if ($part === null || $part->item === null || $part->item->item_type !== 'track' || $part->item->removed_at !== null
            || $part->browserMimeType() === null
            || ($part->duration_ms ?? 0) < 1
            || ($mediaVersion !== null && ! hash_equals($mediaVersion, $part->media_version))) {
            throw new NotFoundHttpException('Playable media part not found.');
        }
        $expectedMachine = (string) config('services.plex.expected_machine_identifier');
        $expectedLibrary = (string) config('services.plex.expected_library_uuid');
        if ($expectedMachine === '' || $expectedLibrary === ''
            || ! hash_equals($expectedMachine, (string) $part->item->library?->server?->machine_identifier)
            || ! hash_equals($expectedLibrary, (string) $part->item->library?->section_uuid)) {
            throw new RuntimeException('Plex playback item is outside the configured machine or library.');
        }

        return $part;
    }

    private function validTransition(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            'stopped' => ['playing', 'paused'],
            'playing' => ['playing', 'paused', 'stopped', 'ended'],
            'paused' => ['playing', 'paused', 'stopped', 'ended'],
            default => [],
        }, true);
    }

    private function duration(PlexMediaPart $part): int
    {
        return (int) $part->duration_ms;
    }

    private function controlLock(string $token): Lock
    {
        $lock = Cache::lock($this->key($token).':control', 120);
        if (! $lock->get()) {
            throw new ConflictHttpException('Playback state is already being updated.');
        }

        return $lock;
    }

    /** @return array{0:int,1:string} */
    private function acquireSessionSlot(User $user): array
    {
        foreach (range(1, 4) as $slot) {
            $lock = Cache::lock($this->sessionSlotKey($user, $slot), self::SESSION_TTL_SECONDS);
            if ($lock->get()) {
                return [$slot, $lock->owner()];
            }
        }

        throw new TooManyRequestsHttpException(30, 'Too many active playback sessions.');
    }

    /** @param array<string, mixed> $session */
    private function releaseSessionSlot(User $user, array $session): void
    {
        if (is_int($session['session_slot'] ?? null) && is_string($session['session_lock_owner'] ?? null)) {
            Cache::restoreLock($this->sessionSlotKey($user, $session['session_slot']), $session['session_lock_owner'])->release();
        }
    }

    private function sessionSlotKey(User $user, int $slot): string
    {
        return "disco:playback-session-slot:{$user->id}:{$slot}";
    }

    /** @param array<string, mixed> $session */
    private function store(string $token, array $session): void
    {
        Cache::put($this->key($token), $session, CarbonImmutable::parse($session['expires_at']));
    }

    private function key(string $token): string
    {
        return 'disco:playback-session:'.hash('sha256', $token);
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
