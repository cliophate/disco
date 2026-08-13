<?php

namespace App\Music\MusicBrainz;

use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\ExternalIdentifier;
use App\Models\Medium;
use App\Models\MediumTrack;
use App\Models\Recording;
use App\Models\Release;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MusicBrainzTracklistProjector
{
    private const TITLE_LIMIT_BYTES = 16384;

    /** @param array<string, mixed> $payload */
    public function project(Release $release, array $payload): void
    {
        $media = $this->validate($payload['media'] ?? []);
        DB::transaction(function () use ($media, $release): void {
            $mediumPositions = [];
            foreach ($media as $mediumPayload) {
                $mediumPositions[] = $mediumPayload['position'];
                $medium = Medium::query()->updateOrCreate(
                    ['release_id' => $release->entity_id, 'position' => $mediumPayload['position']],
                    ['title' => $mediumPayload['title'], 'format' => $mediumPayload['format']],
                );
                $trackPositions = [];
                foreach ($mediumPayload['tracks'] as $trackPayload) {
                    $trackPositions[] = $trackPayload['position'];
                    $recordingId = $this->recording($trackPayload['recording']);
                    MediumTrack::query()->updateOrCreate(
                        ['medium_id' => $medium->id, 'position' => $trackPayload['position']],
                        [
                            'recording_id' => $recordingId,
                            'number_text' => $trackPayload['number'],
                            'title' => $trackPayload['title'],
                            'duration_ms' => $trackPayload['duration_ms'],
                        ],
                    );
                }
                MediumTrack::query()->where('medium_id', $medium->id)->whereNotIn('position', $trackPositions)->delete();
            }
            Medium::query()->where('release_id', $release->entity_id)->whereNotIn('position', $mediumPositions)->delete();
        });
    }

    /** @return list<array{position:int,title:?string,format:?string,tracks:list<array{position:int,number:?string,title:string,duration_ms:?int,recording:?array{id:string,title:string,duration_ms:?int,disambiguation:?string,artist_credit:list<array{name:string,artist_mbid:?string,joinphrase:string}>}>}>} */
    private function validate(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new RuntimeException('MusicBrainz release media was malformed.');
        }
        if ($payload === [] || count($payload) > 20) {
            throw new RuntimeException('MusicBrainz release media was empty or exceeded the medium limit.');
        }
        $result = [];
        $mediumPositions = [];
        $totalTracks = 0;
        foreach ($payload as $medium) {
            if (! is_array($medium) || ! is_int($medium['position'] ?? null) || $medium['position'] < 1
                || $medium['position'] > 65535 || isset($mediumPositions[$medium['position']])
                || ! is_array($medium['tracks'] ?? null) || $medium['tracks'] === []) {
                throw new RuntimeException('MusicBrainz release contained an invalid medium.');
            }
            if ((is_string($medium['title'] ?? null) && strlen($medium['title']) > self::TITLE_LIMIT_BYTES)
                || (is_string($medium['format'] ?? null) && strlen($medium['format']) > 255)) {
                throw new RuntimeException('MusicBrainz release medium text exceeded the storage limit.');
            }
            $mediumPositions[$medium['position']] = true;
            $tracks = [];
            $trackPositions = [];
            foreach ($medium['tracks'] as $track) {
                $recording = is_array($track['recording'] ?? null) ? $track['recording'] : null;
                $position = $track['position'] ?? null;
                $title = $track['title'] ?? $recording['title'] ?? null;
                $duration = $track['length'] ?? $recording['length'] ?? null;
                if (! is_array($track) || ! is_int($position) || $position < 1 || isset($trackPositions[$position])
                    || $position > 65535
                    || ! is_string($title) || trim($title) === ''
                    || strlen($title) > self::TITLE_LIMIT_BYTES
                    || ($duration !== null && (! is_int($duration) || $duration < 0 || $duration > 4294967295))
                    || (is_scalar($track['number'] ?? null) && strlen((string) $track['number']) > 255)) {
                    throw new RuntimeException('MusicBrainz release contained an invalid track.');
                }
                $totalTracks++;
                if ($totalTracks > 500) {
                    throw new RuntimeException('MusicBrainz release exceeded the track limit.');
                }
                $trackPositions[$position] = true;
                $recordingData = null;
                if ($recording !== null && isset($recording['id'])) {
                    if (! is_string($recording['id']) || ! Str::isUuid($recording['id'])) {
                        throw new RuntimeException('MusicBrainz track contained an invalid recording identity.');
                    }
                    $recordingTitle = is_string($recording['title'] ?? null) ? $recording['title'] : $title;
                    if (strlen($recordingTitle) > self::TITLE_LIMIT_BYTES) {
                        throw new RuntimeException('MusicBrainz recording title exceeded the storage limit.');
                    }
                    $recordingData = [
                        'id' => strtolower($recording['id']),
                        'title' => $recordingTitle,
                        'duration_ms' => is_int($recording['length'] ?? null) ? $recording['length'] : null,
                        'disambiguation' => is_string($recording['disambiguation'] ?? null) ? $recording['disambiguation'] : null,
                        'artist_credit' => $this->artistCredits($recording['artist-credit'] ?? $track['artist-credit'] ?? []),
                    ];
                }
                $tracks[] = [
                    'position' => $position,
                    'number' => is_scalar($track['number'] ?? null) ? (string) $track['number'] : null,
                    'title' => trim($title),
                    'duration_ms' => $duration,
                    'recording' => $recordingData,
                ];
            }
            $result[] = [
                'position' => $medium['position'],
                'title' => is_string($medium['title'] ?? null) ? $medium['title'] : null,
                'format' => is_string($medium['format'] ?? null) ? $medium['format'] : null,
                'tracks' => $tracks,
            ];
        }

        return $result;
    }

    /** @param array{id:string,title:string,duration_ms:?int,disambiguation:?string,artist_credit:list<array{name:string,artist_mbid:?string,joinphrase:string}>}|null $payload */
    private function recording(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:musicbrainz-recording:{$payload['id']}"]);
        $identifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.recording')
            ->where('value', $payload['id'])
            ->first();
        if ($identifier !== null && ($identifier->status !== 'active' || $identifier->entity?->kind !== 'recording')) {
            throw new RuntimeException('MusicBrainz recording identity conflicts with the catalog.');
        }
        $entity = $identifier?->entity ?? CatalogEntity::query()->create([
            'kind' => 'recording',
            'status' => 'active',
            'canonical_name' => $payload['title'],
            'sort_name' => $payload['title'],
            'disambiguation' => $payload['disambiguation'],
        ]);
        $identifier = ExternalIdentifier::query()->firstOrCreate(
            ['namespace' => 'musicbrainz.recording', 'value' => $payload['id']],
            ['entity_id' => $entity->id, 'status' => 'active'],
        );
        $entity = $identifier->entity;
        if ($entity === null || $entity->kind !== 'recording') {
            throw new RuntimeException('MusicBrainz recording identity could not be established.');
        }
        Recording::query()->updateOrCreate(
            ['entity_id' => $entity->id],
            ['duration_ms' => $payload['duration_ms']],
        );
        EntityMetadata::query()->updateOrCreate(
            ['entity_id' => $entity->id],
            [
                'source_provider' => 'musicbrainz',
                'artist_credit' => $payload['artist_credit'],
                'enriched_at' => now(),
            ],
        );

        return $entity->id;
    }

    /** @return list<array{name:string,artist_mbid:?string,joinphrase:string}> */
    private function artistCredits(mixed $credits): array
    {
        if (! is_array($credits) || count($credits) > 20) {
            throw new RuntimeException('MusicBrainz recording artist credit was malformed.');
        }

        return collect($credits)->map(function (mixed $credit): array {
            $name = is_array($credit) ? ($credit['name'] ?? data_get($credit, 'artist.name')) : null;
            $mbid = is_array($credit) ? data_get($credit, 'artist.id') : null;
            $joinphrase = is_array($credit) && is_string($credit['joinphrase'] ?? null) ? $credit['joinphrase'] : '';
            if (! is_string($name) || trim($name) === '' || strlen($name) > 255
                || ($mbid !== null && (! is_string($mbid) || ! Str::isUuid($mbid)))
                || strlen($joinphrase) > 64) {
                throw new RuntimeException('MusicBrainz recording artist credit was invalid.');
            }

            return [
                'name' => trim($name),
                'artist_mbid' => is_string($mbid) ? strtolower($mbid) : null,
                'joinphrase' => $joinphrase,
            ];
        })->values()->all();
    }
}
