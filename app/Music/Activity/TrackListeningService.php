<?php

namespace App\Music\Activity;

use App\Models\CatalogEntity;
use App\Models\ListenImportRun;
use App\Models\SourceAccount;
use App\Music\CanonicalEntityResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrackListeningService
{
    public function __construct(private readonly CanonicalEntityResolver $canonicalEntities) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $tracks
     * @return Collection<int, array<string, mixed>>
     */
    public function attach(Collection $tracks, string $userId): Collection
    {
        $recordingIds = $tracks->pluck('_recording_id')->filter(fn ($id): bool => is_string($id))->unique()->values();
        $aliases = $this->canonicalAliases($recordingIds);
        $plex = $this->plexEvidence($aliases);
        [$listenBrainz, $listenBrainzCovered, $listenBrainzAsOf] = $this->listenBrainzEvidence($aliases, $userId);

        return $tracks->map(function (array $track) use ($aliases, $listenBrainz, $listenBrainzAsOf, $listenBrainzCovered, $plex): array {
            $recordingId = is_string($track['_recording_id'] ?? null) ? $aliases->get($track['_recording_id']) : null;
            unset($track['_recording_id'], $track['_plex_item_id']);
            if ($recordingId === null) {
                $track['listening'] = [
                    'identity_status' => 'unmatched',
                    'plex' => $this->emptyEvidence('unmatched_identity'),
                    'listenbrainz' => $this->emptyEvidence('unmatched_identity'),
                ];

                return $track;
            }

            $plexRows = $plex->get($recordingId, collect());
            $knownPlexCounts = $plexRows->pluck('view_count')->filter(fn ($count): bool => $count !== null)->map(fn ($count): int => (int) $count);
            $plexCount = $knownPlexCounts->max();
            $plexStatus = match (true) {
                $plexRows->isEmpty() => 'unsupported_source',
                $plexCount !== null && $plexCount > 0 => 'counted',
                $plexRows->every(fn ($row): bool => $row->view_count !== null) => 'known_zero',
                default => 'unavailable',
            };
            $lastViewed = $plexRows->pluck('last_viewed_at')->filter()->max();
            $plexAsOf = $plexRows->pluck('last_synced_at')->filter()->max();
            $listenBrainzRow = $listenBrainz->get($recordingId);
            $listenBrainzCount = $listenBrainzRow === null ? 0 : (int) $listenBrainzRow->listen_count;
            $listenBrainzStatus = $listenBrainzCount > 0 ? 'counted' : ($listenBrainzCovered ? 'known_zero' : 'unavailable');

            $track['listening'] = [
                'identity_status' => 'exact',
                'plex' => [
                    'status' => $plexStatus,
                    'play_count' => in_array($plexStatus, ['counted', 'known_zero'], true) ? (int) ($plexCount ?? 0) : null,
                    'first_listened_at' => null,
                    'last_listened_at' => $lastViewed,
                    'availability_as_of' => $plexAsOf,
                    'copy_count' => $plexRows->count(),
                    'aggregation' => $plexRows->count() > 1 ? 'maximum_across_exact_copies' : 'exact_copy',
                ],
                'listenbrainz' => [
                    'status' => $listenBrainzStatus,
                    'play_count' => in_array($listenBrainzStatus, ['counted', 'known_zero'], true) ? $listenBrainzCount : null,
                    'first_listened_at' => $listenBrainzRow?->first_listened_at,
                    'last_listened_at' => $listenBrainzRow?->last_listened_at,
                    'availability_as_of' => $listenBrainzAsOf,
                    'copy_count' => null,
                    'aggregation' => 'immutable_exact_events',
                ],
            ];

            return $track;
        });
    }

    /** @param Collection<string, string> $aliases */
    private function plexEvidence(Collection $aliases): Collection
    {
        if ($aliases->isEmpty()) {
            return collect();
        }

        return DB::table('library.plex_entity_matches as matches')
            ->join('library.plex_items as tracks', 'tracks.id', '=', 'matches.plex_item_id')
            ->whereIn('matches.entity_id', $aliases->keys())
            ->where('matches.match_scope', 'recording')
            ->where('matches.status', 'confirmed')
            ->where('tracks.item_type', 'track')
            ->whereNull('tracks.removed_at')
            ->get(['matches.entity_id as recording_id', 'tracks.id', 'tracks.view_count', 'tracks.last_viewed_at', 'tracks.last_synced_at'])
            ->each(fn ($row) => $row->recording_id = $aliases->get($row->recording_id))
            ->groupBy('recording_id');
    }

    /** @param Collection<string, string> $aliases
     * @return array{Collection<string, object>, bool, ?string}
     */
    private function listenBrainzEvidence(Collection $aliases, string $userId): array
    {
        $accounts = SourceAccount::query()
            ->where('owner_user_id', $userId)
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('slug', 'listenbrainz')->where('enabled', true))
            ->get(['id', 'last_success_at']);
        if ($accounts->isEmpty() || $aliases->isEmpty()) {
            return [collect(), false, null];
        }
        $accountIds = $accounts->pluck('id');
        $covered = ListenImportRun::query()->whereIn('source_account_id', $accountIds)
            ->where('mode', 'full')->where('status', 'completed')->exists();
        $asOf = $accounts->pluck('last_success_at')->filter()->max()?->toAtomString();
        $rows = DB::table('activity.listening_event_matches as matches')
            ->join('activity.listening_events as events', 'events.id', '=', 'matches.listening_event_id')
            ->whereIn('events.source_account_id', $accountIds)
            ->whereIn('matches.recording_entity_id', $aliases->keys())
            ->where('matches.status', 'matched')
            ->where('matches.source_present', true)
            ->groupBy('matches.recording_entity_id')
            ->get([
                'matches.recording_entity_id',
                DB::raw('count(*) as listen_count'),
                DB::raw('min(events.listened_at) as first_listened_at'),
                DB::raw('max(events.listened_at) as last_listened_at'),
            ])->each(fn ($row) => $row->recording_entity_id = $aliases->get($row->recording_entity_id))
            ->groupBy('recording_entity_id')
            ->map(function (Collection $rows): object {
                return (object) [
                    'listen_count' => $rows->sum(fn ($row): int => (int) $row->listen_count),
                    'first_listened_at' => $rows->pluck('first_listened_at')->filter()->min(),
                    'last_listened_at' => $rows->pluck('last_listened_at')->filter()->max(),
                ];
            });

        return [$rows, $covered, $asOf];
    }

    /** @param Collection<int, string> $recordingIds
     * @return Collection<string, string>
     */
    private function canonicalAliases(Collection $recordingIds): Collection
    {
        $aliases = $recordingIds->mapWithKeys(function (string $id): array {
            $canonical = $this->canonicalEntities->resolve($id, 'recording');

            return $canonical === null ? [] : [$id => $canonical->id];
        });
        $frontier = $aliases->values()->unique();
        for ($depth = 0; $depth < 10 && $frontier->isNotEmpty(); $depth++) {
            $redirects = CatalogEntity::query()->where('kind', 'recording')->where('status', 'redirected')
                ->whereIn('redirect_entity_id', $frontier)->get(['id', 'redirect_entity_id']);
            $frontier = collect();
            foreach ($redirects as $redirect) {
                if ($aliases->has($redirect->id)) {
                    continue;
                }
                $canonical = $aliases->get($redirect->redirect_entity_id, $redirect->redirect_entity_id);
                $aliases->put($redirect->id, $canonical);
                $frontier->push($redirect->id);
            }
        }

        return $aliases;
    }

    /** @return array<string, mixed> */
    private function emptyEvidence(string $status): array
    {
        return [
            'status' => $status,
            'play_count' => null,
            'first_listened_at' => null,
            'last_listened_at' => null,
            'availability_as_of' => null,
            'copy_count' => null,
            'aggregation' => null,
        ];
    }
}
