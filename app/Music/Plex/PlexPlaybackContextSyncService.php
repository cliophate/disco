<?php

namespace App\Music\Plex;

use App\Models\PlexItem;
use App\Models\PlexLibrary;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PlexPlaybackContextSyncService
{
    public function __construct(private readonly PlexClient $client) {}

    /** @return array{observed:int,matched:int} */
    public function sync(): array
    {
        $lock = Cache::lock('disco:plex-playback-sync', 300);
        if (! $lock->get()) {
            throw new RuntimeException('Another Plex playback context synchronization is running.');
        }
        try {
            $identity = $this->client->identity();
            $expectedMachine = (string) config('services.plex.expected_machine_identifier');
            if ($expectedMachine === '' || ! hash_equals($expectedMachine, $identity['machine_identifier'])) {
                throw new RuntimeException('Plex playback origin machine identifier mismatch.');
            }
            $section = $this->client->musicLibrary((string) config('services.plex.library', 'Music'));
            $expectedLibrary = (string) config('services.plex.expected_library_uuid');
            if ($expectedLibrary === '' || ! hash_equals($expectedLibrary, (string) $section['uuid'])) {
                throw new RuntimeException('Plex playback library UUID mismatch.');
            }
            $library = PlexLibrary::query()->where('section_uuid', $expectedLibrary)
                ->whereHas('server', fn ($query) => $query->where('machine_identifier', $expectedMachine))->firstOrFail();
            $sessions = $this->client->activeSessions();
            $albums = [];
            $priority = ['playing' => 0, 'buffering' => 1, 'paused' => 2];
            foreach ($sessions as $session) {
                $album = PlexItem::query()->where('plex_library_id', $library->id)->where('rating_key', $session['parent_rating_key'])
                    ->where('item_type', 'album')->whereNull('removed_at')->with('matches')->first();
                $trackExists = PlexItem::query()->where('plex_library_id', $library->id)->where('rating_key', $session['rating_key'])
                    ->where('parent_rating_key', $session['parent_rating_key'])->where('item_type', 'track')->whereNull('removed_at')->exists();
                $releaseGroupId = $album?->matches->where('match_scope', 'release_group')->whereIn('status', ['confirmed', 'candidate'])->sortBy(fn ($match): int => $match->status === 'confirmed' ? 0 : 1)->first()?->entity_id;
                if (! $trackExists || ! is_string($releaseGroupId)) {
                    continue;
                }
                if (! isset($albums[$releaseGroupId]) || $priority[$session['state']] < $priority[$albums[$releaseGroupId]['state']]) {
                    $albums[$releaseGroupId] = ['state' => $session['state']];
                }
            }
            $ttl = (int) config('services.plex.playback_context_ttl_seconds', 120);
            $observedAt = now();
            $stored = Cache::put(PlexPlaybackContextService::cacheKey(), [
                'observed_at' => $observedAt->toAtomString(),
                'expires_at' => $observedAt->copy()->addSeconds($ttl)->toAtomString(),
                'albums' => $albums,
            ], $ttl);
            if (! $stored) {
                throw new RuntimeException('Plex playback context could not be cached.');
            }

            return ['observed' => count($sessions), 'matched' => count($albums)];
        } finally {
            $lock->release();
        }
    }
}
