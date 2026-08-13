<?php

namespace App\Music\Discovery;

use App\Jobs\RefreshArtistDiscography;
use App\Models\ArtistDiscographyGeneration;
use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Music\CanonicalEntityResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ArtistDiscographyRefreshService
{
    private const ACTIVE_TIMEOUT_SECONDS = 3700;

    private const FAILED_COOLDOWN_SECONDS = 300;

    public function __construct(private readonly CanonicalEntityResolver $canonicalEntities) {}

    /** @return array{status:string,requested_at:?string,started_at:?string,finished_at:?string,generation_id:?string,message:?string} */
    public function request(string $artistId, bool $force = false): array
    {
        $artist = $this->canonicalEntities->resolve($artistId, 'agent');
        if ($artist === null) {
            abort(404, 'Artist not found.');
        }
        if (! $this->hasExactIdentity($artist->id)) {
            return $this->unavailable();
        }

        $lock = Cache::lock($this->requestLockKey($artist->id), 10);
        if (! $lock->get()) {
            return $this->statusFor($artist->id);
        }

        try {
            $status = $this->statusFor($artist->id);
            $latest = ArtistDiscographyGeneration::query()
                ->whereIn('artist_entity_id', $this->identityIds($artist->id))
                ->latest('generated_at')
                ->first();
            if (! $force && $latest?->expires_at?->isFuture()) {
                return $status;
            }
            if (in_array($status['status'], ['queued', 'running'], true)) {
                return $status;
            }
            if (! $force && $status['status'] === 'failed' && $status['finished_at'] !== null
                && now()->diffInSeconds($status['finished_at'], true) < self::FAILED_COOLDOWN_SECONDS) {
                return $status;
            }

            $status = [
                'status' => 'queued',
                'requested_at' => now()->toAtomString(),
                'started_at' => null,
                'finished_at' => null,
                'generation_id' => null,
                'message' => 'Refresh queued.',
            ];
            $this->store($artist->id, $status);
            try {
                RefreshArtistDiscography::dispatch($artist->id);
            } catch (Throwable) {
                return $this->markFailed($artist->id);
            }

            return $status;
        } finally {
            $lock->release();
        }
    }

    /** @return array{status:string,requested_at:?string,started_at:?string,finished_at:?string,generation_id:?string,message:?string} */
    public function status(string $artistId): array
    {
        $artist = $this->canonicalEntities->resolve($artistId, 'agent');
        if ($artist === null) {
            abort(404, 'Artist not found.');
        }
        if (! $this->hasExactIdentity($artist->id)) {
            return $this->unavailable();
        }

        return $this->statusFor($artist->id);
    }

    public function markRunning(string $artistId): void
    {
        $current = $this->statusFor($artistId);
        $this->store($artistId, [
            ...$current,
            'status' => 'running',
            'started_at' => now()->toAtomString(),
            'finished_at' => null,
            'message' => 'Refreshing from MusicBrainz.',
        ]);
    }

    public function markSucceeded(string $artistId, string $generationId): void
    {
        $current = $this->statusFor($artistId);
        $this->store($artistId, [
            ...$current,
            'status' => 'succeeded',
            'finished_at' => now()->toAtomString(),
            'generation_id' => $generationId,
            'message' => 'Discography refreshed.',
        ]);
    }

    /** @return array{status:string,requested_at:?string,started_at:?string,finished_at:?string,generation_id:?string,message:?string} */
    public function markFailed(string $artistId): array
    {
        $current = Cache::get($this->statusKey($artistId));
        if (! is_array($current)) {
            $current = $this->idle();
        }
        $failed = [
            ...$current,
            'status' => 'failed',
            'finished_at' => now()->toAtomString(),
            'generation_id' => null,
            'message' => 'The refresh failed. Try again later.',
        ];
        $this->store($artistId, $failed);

        return $failed;
    }

    /** @return array{status:string,requested_at:?string,started_at:?string,finished_at:?string,generation_id:?string,message:?string} */
    private function statusFor(string $artistId): array
    {
        $status = Cache::get($this->statusKey($artistId));
        if (! is_array($status)) {
            return $this->idle();
        }
        if (in_array($status['status'] ?? null, ['queued', 'running'], true)) {
            $basis = $status['started_at'] ?? $status['requested_at'] ?? null;
            if (is_string($basis) && now()->diffInSeconds($basis, true) > self::ACTIVE_TIMEOUT_SECONDS) {
                return $this->markFailed($artistId);
            }
        }

        return [
            'status' => (string) ($status['status'] ?? 'idle'),
            'requested_at' => is_string($status['requested_at'] ?? null) ? $status['requested_at'] : null,
            'started_at' => is_string($status['started_at'] ?? null) ? $status['started_at'] : null,
            'finished_at' => is_string($status['finished_at'] ?? null) ? $status['finished_at'] : null,
            'generation_id' => is_string($status['generation_id'] ?? null) ? $status['generation_id'] : null,
            'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
        ];
    }

    /** @param array<string,mixed> $status */
    private function store(string $artistId, array $status): void
    {
        Cache::put($this->statusKey($artistId), $status, now()->addDays(2));
    }

    private function hasExactIdentity(string $artistId): bool
    {
        return ExternalIdentifier::query()
            ->where('entity_id', $artistId)
            ->where('namespace', 'musicbrainz.artist')
            ->where('status', 'active')
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
            ->map(fn (string $value): string => strtolower($value))
            ->unique()
            ->count() === 1;
    }

    /** @return list<string> */
    private function identityIds(string $artistId): array
    {
        $all = collect([$artistId]);
        $frontier = $all;
        for ($depth = 0; $depth < 10 && $frontier->isNotEmpty(); $depth++) {
            $frontier = CatalogEntity::query()->whereIn('redirect_entity_id', $frontier)->pluck('id')
                ->reject(fn (string $id): bool => $all->contains($id))->values();
            $all = $all->merge($frontier)->unique()->values();
        }

        return $all->all();
    }

    private function statusKey(string $artistId): string
    {
        return "disco:artist-discography-refresh:{$artistId}";
    }

    private function requestLockKey(string $artistId): string
    {
        return "disco:artist-discography-refresh-request:{$artistId}";
    }

    /** @return array{status:string,requested_at:null,started_at:null,finished_at:null,generation_id:null,message:null} */
    private function idle(): array
    {
        return ['status' => 'idle', 'requested_at' => null, 'started_at' => null, 'finished_at' => null, 'generation_id' => null, 'message' => null];
    }

    /** @return array{status:string,requested_at:null,started_at:null,finished_at:null,generation_id:null,message:string} */
    private function unavailable(): array
    {
        return ['status' => 'unavailable', 'requested_at' => null, 'started_at' => null, 'finished_at' => null, 'generation_id' => null, 'message' => 'An exact MusicBrainz artist identity is required.'];
    }
}
