<?php

namespace App\Music\ListenBrainz;

use App\Models\ListeningEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListenBrainzMatcher
{
    /**
     * @param  Collection<int, ListeningEvent>  $events
     * @return array{matched:int,unmatched:int,conflicts:int,changed:int}
     */
    public function match(Collection $events, string $runId, bool $trackLastSeen = false): array
    {
        $counts = ['matched' => 0, 'unmatched' => 0, 'conflicts' => 0, 'changed' => 0];
        if ($events->isEmpty()) {
            return $counts;
        }

        $recordingMbids = $events->pluck('recording_mbid')->filter()->unique()->values()->all();
        $releaseMbids = $events->pluck('release_mbid')->filter()->unique()->values()->all();
        $releaseGroupMbids = $events->pluck('release_group_mbid')->filter()->unique()->values()->all();

        $recordings = $this->identifierMap('musicbrainz.recording', 'recording', $recordingMbids);
        $releases = $this->releaseMap($releaseMbids);
        $releaseGroups = $this->identifierMap('musicbrainz.release_group', 'release_group', $releaseGroupMbids);
        $trackRows = $this->plexTracks(array_values(array_unique(array_values($recordings))));
        $tracksByRecording = collect($trackRows)->groupBy('recording_entity_id');
        $existing = DB::table('activity.listening_event_matches')
            ->whereIn('listening_event_id', $events->pluck('id'))
            ->get()
            ->keyBy('listening_event_id');
        $now = now();
        $rows = [];
        $unchangedSeenEventIds = [];

        foreach ($events as $event) {
            $recordingId = $event->recording_mbid ? ($recordings[$event->recording_mbid] ?? null) : null;
            $releaseIds = array_values(array_unique(array_filter([
                $event->release_mbid ? ($releases[$event->release_mbid] ?? null) : null,
                $event->release_group_mbid ? ($releaseGroups[$event->release_group_mbid] ?? null) : null,
            ])));
            $eventTracks = $recordingId === null
                ? collect()
                : $tracksByRecording->get($recordingId, collect());
            $trackReleaseIds = $eventTracks->pluck('release_group_entity_id')->unique()->values();
            $identifierConflicts = $event->identifier_conflicts ?? [];
            $hasSubmittedReleaseIdentifier = $event->release_mbid !== null || $event->release_group_mbid !== null;
            $conflict = $identifierConflicts !== [] || count($releaseIds) > 1;
            $releaseGroupId = count($releaseIds) === 1 ? $releaseIds[0] : null;

            if (! $conflict && $releaseGroupId !== null && $trackReleaseIds->isNotEmpty()
                && ! $trackReleaseIds->contains($releaseGroupId)) {
                $conflict = true;
            }
            if (! $conflict && ! $hasSubmittedReleaseIdentifier && $releaseGroupId === null && $trackReleaseIds->count() === 1) {
                $releaseGroupId = $trackReleaseIds->first();
            }

            $candidateTracks = $releaseGroupId === null
                ? collect()
                : $eventTracks->where('release_group_entity_id', $releaseGroupId)->pluck('plex_track_item_id')->unique()->values();
            $plexTrackId = $candidateTracks->count() === 1 ? $candidateTracks->first() : null;
            $ambiguousRecordingAlbums = ! $conflict && $releaseGroupId === null && $trackReleaseIds->count() > 1;
            $status = $conflict ? 'conflict' : ($releaseGroupId === null ? 'unmatched' : 'matched');
            $counts[$status === 'conflict' ? 'conflicts' : $status]++;
            $method = $status === 'conflict' ? 'identifier_conflict' : match (true) {
                $recordingId !== null && count($releaseIds) === 1 => 'musicbrainz_exact',
                count($releaseIds) === 1 => 'release_exact',
                $recordingId !== null && $releaseGroupId !== null => 'recording_plex_exact',
                default => 'unmatched',
            };

            $evidence = [
                'recording_mbid' => $event->recording_mbid,
                'release_mbid' => $event->release_mbid,
                'release_group_mbid' => $event->release_group_mbid,
                'identifier_conflicts' => $identifierConflicts,
                'plex_track_candidates' => $candidateTracks->count(),
                'recording_album_candidates' => $trackReleaseIds->count(),
                'ambiguous_recording_albums' => $ambiguousRecordingAlbums,
                'submitted_release_identifier' => $hasSubmittedReleaseIdentifier,
            ];
            $row = [
                'id' => (string) Str::uuid(),
                'listening_event_id' => $event->id,
                'recording_entity_id' => $recordingId,
                'release_group_entity_id' => $conflict ? null : $releaseGroupId,
                'plex_track_item_id' => $conflict ? null : $plexTrackId,
                'status' => $status,
                'method' => $method,
                'confidence' => $status === 'matched' ? 1 : 0,
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
                'source_present' => true,
                'last_seen_import_run_id' => $runId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $current = $existing->get($event->id);
            if ($current === null || ! $this->sameProjection($current, $row, $evidence)) {
                $rows[] = $row;
                $counts['changed']++;
            } elseif ($trackLastSeen && (string) $current->last_seen_import_run_id !== $runId) {
                $unchangedSeenEventIds[] = $event->id;
            }
        }

        if ($rows !== []) {
            DB::table('activity.listening_event_matches')->upsert(
                $rows,
                ['listening_event_id'],
                [
                    'recording_entity_id', 'release_group_entity_id', 'plex_track_item_id',
                    'status', 'method', 'confidence', 'evidence', 'source_present',
                    'last_seen_import_run_id', 'updated_at',
                ],
            );
        }
        if ($unchangedSeenEventIds !== []) {
            DB::table('activity.listening_event_matches')
                ->whereIn('listening_event_id', $unchangedSeenEventIds)
                ->update(['last_seen_import_run_id' => $runId]);
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $evidence
     */
    private function sameProjection(object $current, array $desired, array $evidence): bool
    {
        $currentEvidence = is_string($current->evidence)
            ? json_decode($current->evidence, true, 512, JSON_THROW_ON_ERROR)
            : $current->evidence;

        return ($current->recording_entity_id === null ? null : (string) $current->recording_entity_id) === $desired['recording_entity_id']
            && ($current->release_group_entity_id === null ? null : (string) $current->release_group_entity_id) === $desired['release_group_entity_id']
            && ($current->plex_track_item_id === null ? null : (string) $current->plex_track_item_id) === $desired['plex_track_item_id']
            && $current->status === $desired['status']
            && $current->method === $desired['method']
            && (float) $current->confidence === (float) $desired['confidence']
            && $currentEvidence == $evidence
            && (bool) $current->source_present === $desired['source_present'];
    }

    /** @param list<string> $values
     * @return array<string, string>
     */
    private function identifierMap(string $namespace, string $kind, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return DB::table('catalog.external_identifiers as identifiers')
            ->join('catalog.entities as entities', 'entities.id', '=', 'identifiers.entity_id')
            ->where('identifiers.namespace', $namespace)
            ->where('identifiers.status', 'active')
            ->where('entities.kind', $kind)
            ->where('entities.status', 'active')
            ->whereIn('identifiers.value', $values)
            ->pluck('identifiers.entity_id', 'identifiers.value')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /** @param list<string> $values
     * @return array<string, string>
     */
    private function releaseMap(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return DB::table('catalog.external_identifiers as identifiers')
            ->join('catalog.entities as entities', 'entities.id', '=', 'identifiers.entity_id')
            ->join('catalog.releases as releases', 'releases.entity_id', '=', 'entities.id')
            ->join('catalog.entities as release_groups', 'release_groups.id', '=', 'releases.release_group_id')
            ->where('identifiers.namespace', 'musicbrainz.release')
            ->where('identifiers.status', 'active')
            ->where('entities.kind', 'release')
            ->where('entities.status', 'active')
            ->where('release_groups.kind', 'release_group')
            ->where('release_groups.status', 'active')
            ->whereIn('identifiers.value', $values)
            ->pluck('releases.release_group_id', 'identifiers.value')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /** @param list<string> $recordingIds
     * @return list<object{recording_entity_id:string,plex_track_item_id:string,release_group_entity_id:string}>
     */
    private function plexTracks(array $recordingIds): array
    {
        if ($recordingIds === []) {
            return [];
        }

        return DB::table('library.plex_entity_matches as recording_matches')
            ->join('library.plex_items as tracks', function ($join): void {
                $join->on('tracks.id', '=', 'recording_matches.plex_item_id')
                    ->where('tracks.item_type', 'track')
                    ->whereNull('tracks.removed_at');
            })
            ->join('library.plex_items as albums', function ($join): void {
                $join->on('albums.plex_library_id', '=', 'tracks.plex_library_id')
                    ->on('albums.rating_key', '=', 'tracks.parent_rating_key')
                    ->where('albums.item_type', 'album')
                    ->whereNull('albums.removed_at');
            })
            ->join('library.plex_entity_matches as album_matches', function ($join): void {
                $join->on('album_matches.plex_item_id', '=', 'albums.id')
                    ->where('album_matches.match_scope', 'release_group')
                    ->where('album_matches.status', 'confirmed');
            })
            ->join('catalog.entities as album_entities', 'album_entities.id', '=', 'album_matches.entity_id')
            ->where('album_entities.kind', 'release_group')
            ->where('album_entities.status', 'active')
            ->where('recording_matches.match_scope', 'recording')
            ->where('recording_matches.status', 'confirmed')
            ->whereIn('recording_matches.entity_id', $recordingIds)
            ->distinct()
            ->get([
                'recording_matches.entity_id as recording_entity_id',
                'tracks.id as plex_track_item_id',
                'album_matches.entity_id as release_group_entity_id',
            ])->all();
    }
}
