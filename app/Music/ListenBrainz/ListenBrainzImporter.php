<?php

namespace App\Music\ListenBrainz;

use App\Models\ListenImportRun;
use App\Models\ListeningEvent;
use App\Models\SourceAccount;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ListenBrainzImporter
{
    public function __construct(
        private readonly ListenBrainzClient $client,
        private readonly ListenBrainzMatcher $matcher,
    ) {}

    /** @return array{status:string,requested:int,inserted:int,existing:int,matched:int,unmatched:int,conflicts:int,changed:int,pages:int} */
    public function import(bool $full = false, int $maxPages = 0): array
    {
        if ($maxPages < 0) {
            throw new RuntimeException('--max-pages must be zero or greater.');
        }
        if (! $this->client->configured()) {
            throw new RuntimeException('ListenBrainz ingestion requires LISTENBRAINZ_USERNAME and LISTENBRAINZ_TOKEN.');
        }

        $lock = Cache::lock('disco:listenbrainz-sync', 86400);
        if (! $lock->get()) {
            throw new RuntimeException('Another ListenBrainz import is already running.');
        }

        try {
            return $this->performImport($full, $maxPages);
        } finally {
            $lock->release();
        }
    }

    /** @return array{status:string,requested:int,inserted:int,existing:int,matched:int,unmatched:int,conflicts:int,changed:int,pages:int} */
    private function performImport(bool $full, int $maxPages): array
    {
        $username = (string) config('services.listenbrainz.username');
        [$provider, $account, $accountStateChanged] = $this->resolveAccount($username);

        $mode = $full ? 'full' : 'incremental';
        $startCursor = $account->cursor ?? [];
        $run = ListenImportRun::query()->create([
            'source_account_id' => $account->id,
            'mode' => $mode,
            'status' => 'running',
            'start_cursor' => $startCursor,
            'end_cursor' => $startCursor,
            'counts' => $this->emptyCounts(),
            'errors' => [],
            'started_at' => now(),
        ]);
        $counts = $this->emptyCounts();
        $pageSize = min(1000, max(1, (int) config('services.listenbrainz.page_size', 1000)));
        $lowerBound = $full ? null : max(
            0,
            (int) ($startCursor['latest_listened_at'] ?? 0)
                - (int) config('services.listenbrainz.overlap_seconds', 3600),
        );
        $minTimestamp = $lowerBound;
        $maxTimestamp = null;
        $previousBoundaryTimestamp = null;
        $newestTimestamp = null;
        $complete = false;
        $activityDataChanged = false;

        try {
            $this->client->validateToken();

            while (true) {
                if ($maxPages > 0 && $counts['pages'] >= $maxPages) {
                    break;
                }
                if ($counts['pages'] >= 10_000) {
                    throw new RuntimeException('ListenBrainz pagination exceeded the 10000-page safety limit.');
                }

                $payload = $this->client->listens($minTimestamp, $maxTimestamp, $pageSize);
                $providerListens = $payload['payload']['listens'];
                if ($providerListens !== []) {
                    $pageNewestTimestamp = max(array_column($providerListens, 'listened_at'));
                    $newestTimestamp = max($newestTimestamp ?? 0, $pageNewestTimestamp);
                }
                $pageCounts = DB::transaction(fn (): array => $this->persistPage(
                    $provider,
                    $account,
                    $run,
                    $payload,
                    $providerListens,
                    $minTimestamp,
                    $maxTimestamp,
                    $pageSize,
                    $full,
                ));
                foreach (['requested', 'inserted', 'existing', 'matched', 'unmatched', 'conflicts', 'changed'] as $key) {
                    $counts[$key] += $pageCounts[$key];
                }
                $activityDataChanged = $activityDataChanged
                    || $pageCounts['inserted'] > 0
                    || $pageCounts['changed'] > 0;
                $counts['pages']++;

                if (count($providerListens) < $pageSize) {
                    $complete = true;
                    break;
                }

                if ($full) {
                    $boundaryTimestamp = min(array_column($providerListens, 'listened_at'));
                    if ($previousBoundaryTimestamp !== null && $boundaryTimestamp >= $previousBoundaryTimestamp) {
                        throw new RuntimeException('ListenBrainz full pagination did not make strictly decreasing progress.');
                    }
                    $previousBoundaryTimestamp = $boundaryTimestamp;
                    $maxTimestamp = $boundaryTimestamp + 1;
                } else {
                    $boundaryTimestamp = max(array_column($providerListens, 'listened_at'));
                    if ($previousBoundaryTimestamp !== null && $boundaryTimestamp <= $previousBoundaryTimestamp) {
                        throw new RuntimeException('ListenBrainz incremental pagination did not make strictly increasing progress.');
                    }
                    $previousBoundaryTimestamp = $boundaryTimestamp;
                    $minTimestamp = max((int) $lowerBound, $boundaryTimestamp - 1);
                }
                $rateIntervalMs = max(0, (int) config('services.listenbrainz.rate_interval_ms', 250));
                if ($rateIntervalMs > 0) {
                    usleep($rateIntervalMs * 1000);
                }
            }

            DB::transaction(function () use (
                $account,
                $accountStateChanged,
                &$activityDataChanged,
                $complete,
                &$counts,
                $full,
                $newestTimestamp,
                $run,
            ): void {
                if ($full && $complete) {
                    $removed = DB::update(<<<'SQL'
                        UPDATE activity.listening_event_matches AS matches
                        SET source_present = false, updated_at = CURRENT_TIMESTAMP
                        WHERE matches.last_seen_import_run_id <> ?
                          AND matches.source_present = true
                          AND EXISTS (
                              SELECT 1 FROM activity.listening_events AS events
                              WHERE events.id = matches.listening_event_id
                                AND events.source_account_id = ?
                          )
                    SQL, [$run->id, $account->id]);
                    $counts['changed'] += $removed;
                    $activityDataChanged = $activityDataChanged || $removed > 0;
                }
                if ($accountStateChanged || $activityDataChanged) {
                    $this->rebuildAggregates();
                }

                $account->refresh();
                $cursor = $account->cursor ?? [];
                if ($complete && $newestTimestamp !== null) {
                    $cursor['latest_listened_at'] = max(
                        (int) ($cursor['latest_listened_at'] ?? 0),
                        $newestTimestamp,
                    );
                }
                if ($complete) {
                    $cursor['last_page_retrieved_at'] = now()->toIso8601String();
                }
                if ($full && $complete) {
                    $cursor['last_full_completed_at'] = now()->toIso8601String();
                }
                if ($activityDataChanged) {
                    $cursor['activity_revision'] = (int) ($cursor['activity_revision'] ?? 0) + 1;
                }
                $account->update([
                    'cursor' => $cursor,
                    'status' => 'active',
                    'last_success_at' => now(),
                    'last_error_at' => null,
                    'last_error' => null,
                ]);
                $run->update([
                    'status' => $complete ? 'completed' : 'incomplete',
                    'end_cursor' => $cursor,
                    'counts' => $counts,
                    'completed_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $error = $this->safeError($exception);
            DB::transaction(function () use (
                $account,
                $accountStateChanged,
                $activityDataChanged,
                $counts,
                $error,
                $run,
            ): void {
                if ($accountStateChanged || $activityDataChanged) {
                    $this->rebuildAggregates();
                }
                $account->refresh();
                $cursor = $account->cursor ?? [];
                if ($activityDataChanged) {
                    $cursor['activity_revision'] = (int) ($cursor['activity_revision'] ?? 0) + 1;
                }
                $account->update([
                    'cursor' => $cursor,
                    'status' => 'active',
                    'last_error_at' => now(),
                    'last_error' => $error,
                ]);
                $run->update([
                    'status' => 'failed',
                    'end_cursor' => $cursor,
                    'counts' => $counts,
                    'errors' => [['message' => $error]],
                    'completed_at' => now(),
                ]);
            });

            throw $exception;
        }

        return ['status' => $complete ? 'completed' : 'incomplete', ...$counts];
    }

    /** @return array{SourceProvider,SourceAccount,bool} */
    private function resolveAccount(string $username): array
    {
        return DB::transaction(function () use ($username): array {
            $owner = User::query()->where('is_owner', true)->first();
            if ($owner === null) {
                throw new RuntimeException('Create the Disco owner before importing ListenBrainz activity.');
            }
            $provider = SourceProvider::query()->firstOrCreate(
                ['slug' => 'listenbrainz'],
                [
                    'display_name' => 'ListenBrainz',
                    'enabled' => true,
                    'policy' => ['storage' => 'private', 'connector' => 'read_only'],
                ],
            );
            $account = SourceAccount::query()->firstOrCreate(
                [
                    'provider_id' => $provider->id,
                    'owner_user_id' => $owner->id,
                    'external_username' => $username,
                ],
                [
                    'credential_env_key' => 'LISTENBRAINZ_TOKEN',
                    'cursor' => ['activity_revision' => 0],
                    'status' => 'active',
                ],
            );
            $accounts = SourceAccount::query()
                ->where('provider_id', $provider->id)
                ->where('owner_user_id', $owner->id)
                ->lockForUpdate()
                ->get();
            $highestRevision = $accounts->max(
                fn (SourceAccount $sourceAccount): int => (int) (($sourceAccount->cursor ?? [])['activity_revision'] ?? 0),
            ) ?? 0;
            $stateChanged = $account->wasRecentlyCreated;

            foreach ($accounts as $sourceAccount) {
                if ($sourceAccount->id === $account->id || $sourceAccount->status === 'inactive') {
                    continue;
                }
                $cursor = $sourceAccount->cursor ?? [];
                $cursor['activity_revision'] = (int) ($cursor['activity_revision'] ?? 0) + 1;
                $sourceAccount->update(['cursor' => $cursor, 'status' => 'inactive']);
                $stateChanged = true;
            }
            if ($account->status !== 'active') {
                $stateChanged = true;
            }
            if ($stateChanged) {
                $cursor = $account->cursor ?? [];
                $cursor['activity_revision'] = $highestRevision + 1;
                $account->update(['cursor' => $cursor, 'status' => 'active']);
            }

            return [$provider, $account->fresh(), $stateChanged];
        });
    }

    /**
     * @param  array{payload:array{user_id:string,listens:list<array<string,mixed>>}}  $payload
     * @param  list<array<string, mixed>>  $listens
     * @return array{requested:int,inserted:int,existing:int,matched:int,unmatched:int,conflicts:int,changed:int}
     */
    private function persistPage(
        SourceProvider $provider,
        SourceAccount $account,
        ListenImportRun $run,
        array $payload,
        array $listens,
        ?int $minTimestamp,
        ?int $maxTimestamp,
        int $pageSize,
        bool $trackLastSeen,
    ): array {
        $retrievedAt = now();
        $sourceObject = SourceObject::query()->firstOrCreate(
            [
                'provider_id' => $provider->id,
                'object_type' => 'user_listens',
                'external_id' => 'user:'.$account->external_username,
            ],
            ['canonical_url' => null, 'first_seen_at' => $retrievedAt, 'last_seen_at' => $retrievedAt],
        );
        if (! $sourceObject->wasRecentlyCreated) {
            $sourceObject->update(['last_seen_at' => $retrievedAt]);
        }
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $providerListens = $payload['payload']['listens'];
        $timestamps = array_column($providerListens, 'listened_at');
        $receipt = [
            'request' => ['min_ts' => $minTimestamp, 'max_ts' => $maxTimestamp, 'count' => $pageSize],
            'provider' => [
                'count' => is_int($payload['payload']['count'] ?? null)
                    ? $payload['payload']['count']
                    : count($providerListens),
                'user_id' => $payload['payload']['user_id'],
            ],
            'oldest_listened_at' => $timestamps === [] ? null : min($timestamps),
            'newest_listened_at' => $timestamps === [] ? null : max($timestamps),
        ];
        $snapshot = SourceSnapshot::query()->firstOrCreate(
            [
                'source_object_id' => $sourceObject->id,
                'payload_hash' => hash('sha256', $encodedPayload),
            ],
            [
                'retrieved_at' => $retrievedAt,
                'http_status' => 200,
                'payload' => $receipt,
                'parser_version' => 'listenbrainz-json-v1',
            ],
        );

        $rows = [];
        foreach ($listens as $listen) {
            $row = $this->eventRow($account, $snapshot, $listen, $retrievedAt);
            $rows[$row['fingerprint']] = $row;
        }
        $fingerprints = array_keys($rows);
        $existingBefore = $fingerprints === [] ? 0 : ListeningEvent::query()
            ->where('source_account_id', $account->id)
            ->whereIn('fingerprint', $fingerprints)
            ->count();
        if ($rows !== []) {
            DB::table('activity.listening_events')->insertOrIgnore(array_values($rows));
        }

        /** @var Collection<int, ListeningEvent> $events */
        $events = $fingerprints === [] ? new Collection : ListeningEvent::query()
            ->where('source_account_id', $account->id)
            ->whereIn('fingerprint', $fingerprints)
            ->get();
        foreach ($events as $event) {
            $latest = $rows[$event->fingerprint] ?? null;
            if ($latest === null) {
                continue;
            }
            foreach (['recording_mbid', 'release_mbid', 'release_group_mbid', 'identifier_conflicts'] as $field) {
                $event->setAttribute(
                    $field,
                    $field === 'identifier_conflicts'
                        ? json_decode($latest[$field], true, 512, JSON_THROW_ON_ERROR)
                        : $latest[$field],
                );
            }
        }
        $matchCounts = $this->matcher->match($events, $run->id, $trackLastSeen);
        $inserted = count($rows) - $existingBefore;

        return [
            'requested' => count($listens),
            'inserted' => $inserted,
            'existing' => count($listens) - $inserted,
            ...$matchCounts,
        ];
    }

    /** @param array<string, mixed> $listen
     * @return array<string, mixed>
     */
    private function eventRow(SourceAccount $account, SourceSnapshot $snapshot, array $listen, mixed $createdAt): array
    {
        $track = $listen['track_metadata'];
        $additional = is_array($track['additional_info'] ?? null) ? $track['additional_info'] : [];
        $mapping = is_array($track['mbid_mapping'] ?? null) ? $track['mbid_mapping'] : [];
        [$recordingMbid, $recordingConflict] = $this->identifier(
            $additional['recording_mbid'] ?? null,
            $mapping['recording_mbid'] ?? null,
        );
        [$releaseMbid, $releaseConflict] = $this->identifier(
            $additional['release_mbid'] ?? null,
            null,
        );
        [$releaseGroupMbid, $releaseGroupConflict] = $this->identifier(
            $additional['release_group_mbid'] ?? null,
            null,
        );
        $identifierConflicts = array_values(array_filter([
            $recordingConflict ? 'recording_mbid' : null,
            $releaseConflict ? 'release_mbid' : null,
            $releaseGroupConflict ? 'release_group_mbid' : null,
        ]));
        $durationMs = $additional['duration_ms'] ?? $track['duration_ms'] ?? null;
        if ($durationMs === null && is_numeric($additional['duration'] ?? null)) {
            $durationMs = (int) $additional['duration'] * 1000;
        }
        $durationMs = is_numeric($durationMs) && (int) $durationMs >= 0 && (int) $durationMs <= 4_294_967_295
            ? (int) $durationMs
            : null;
        $recordingMsid = $this->uuid($listen['recording_msid'] ?? $additional['recording_msid'] ?? null);
        $releaseName = is_string($track['release_name'] ?? null) ? $track['release_name'] : null;
        $fingerprintIdentity = $recordingMsid === null ? [
            'listened_at' => $listen['listened_at'],
            'artist' => $this->normalizedIdentityString($track['artist_name']),
            'release' => $this->normalizedIdentityString($releaseName),
            'track' => $this->normalizedIdentityString($track['track_name']),
        ] : [
            'listened_at' => $listen['listened_at'],
            'recording_msid' => $recordingMsid,
        ];
        $fields = [
            'release' => $releaseName,
            'recording_msid' => $recordingMsid,
            'recording_mbid' => $recordingMbid,
            'release_mbid' => $releaseMbid,
            'release_group_mbid' => $releaseGroupMbid,
            'duration_ms' => $durationMs,
            'music_service_name' => $this->string($additional['music_service_name'] ?? $additional['music_service'] ?? null),
            'media_player' => $this->string($additional['media_player'] ?? null),
            'submission_client' => $this->string($additional['submission_client'] ?? null),
        ];

        return [
            'id' => (string) Str::uuid(),
            'source_account_id' => $account->id,
            'source_snapshot_id' => $snapshot->id,
            'fingerprint' => hash('sha256', json_encode($fingerprintIdentity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'listened_at' => CarbonImmutable::createFromTimestampUTC($listen['listened_at']),
            'listened_at_epoch' => $listen['listened_at'],
            'supplied_artist' => $track['artist_name'],
            'supplied_release' => $fields['release'],
            'supplied_track' => $track['track_name'],
            'recording_msid' => $fields['recording_msid'],
            'recording_mbid' => $recordingMbid,
            'release_mbid' => $releaseMbid,
            'release_group_mbid' => $releaseGroupMbid,
            'identifier_conflicts' => json_encode($identifierConflicts, JSON_THROW_ON_ERROR),
            'duration_ms' => $durationMs,
            'music_service_name' => $fields['music_service_name'],
            'media_player' => $fields['media_player'],
            'submission_client' => $fields['submission_client'],
            'raw_additional_info' => json_encode($additional, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => $createdAt,
        ];
    }

    private function rebuildAggregates(): void
    {
        DB::statement(<<<'SQL'
            WITH current_aggregates AS (
                SELECT matches.release_group_entity_id, COUNT(*) AS play_count,
                       MIN(events.listened_at) AS first_listened_at, MAX(events.listened_at) AS last_listened_at
                FROM activity.listening_event_matches AS matches
                JOIN activity.listening_events AS events ON events.id = matches.listening_event_id
                JOIN source.accounts AS accounts ON accounts.id = events.source_account_id
                WHERE matches.source_present = true
                  AND matches.status = 'matched'
                  AND matches.release_group_entity_id IS NOT NULL
                  AND accounts.status = 'active'
                GROUP BY matches.release_group_entity_id
            )
            INSERT INTO activity.play_aggregates (
                release_group_entity_id, play_count, first_listened_at, last_listened_at, created_at, updated_at
            )
            SELECT release_group_entity_id, play_count, first_listened_at, last_listened_at,
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM current_aggregates
            ON CONFLICT (release_group_entity_id) DO UPDATE SET
                play_count = EXCLUDED.play_count,
                first_listened_at = EXCLUDED.first_listened_at,
                last_listened_at = EXCLUDED.last_listened_at,
                updated_at = CURRENT_TIMESTAMP
            WHERE activity.play_aggregates.play_count IS DISTINCT FROM EXCLUDED.play_count
               OR activity.play_aggregates.first_listened_at IS DISTINCT FROM EXCLUDED.first_listened_at
               OR activity.play_aggregates.last_listened_at IS DISTINCT FROM EXCLUDED.last_listened_at
        SQL);
        DB::statement(<<<'SQL'
            DELETE FROM activity.play_aggregates AS aggregates
            WHERE NOT EXISTS (
                SELECT 1
                FROM activity.listening_event_matches AS matches
                JOIN activity.listening_events AS events ON events.id = matches.listening_event_id
                JOIN source.accounts AS accounts ON accounts.id = events.source_account_id
                WHERE matches.release_group_entity_id = aggregates.release_group_entity_id
                  AND matches.source_present = true
                  AND matches.status = 'matched'
                  AND accounts.status = 'active'
            )
        SQL);
    }

    /** @return array{requested:int,inserted:int,existing:int,matched:int,unmatched:int,conflicts:int,changed:int,pages:int} */
    private function emptyCounts(): array
    {
        return [
            'requested' => 0,
            'inserted' => 0,
            'existing' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'conflicts' => 0,
            'changed' => 0,
            'pages' => 0,
        ];
    }

    /** @return array{?string,bool} */
    private function identifier(mixed $submitted, mixed $mapped): array
    {
        $submittedUuid = $this->uuid($submitted);
        $mappedUuid = $this->uuid($mapped);

        return [
            $submittedUuid ?? $mappedUuid,
            $submittedUuid !== null && $mappedUuid !== null && $submittedUuid !== $mappedUuid,
        ];
    }

    private function normalizedIdentityString(?string $value): ?string
    {
        return $value === null ? null : Str::lower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, 255, '') : null;
    }

    private function safeError(Throwable $exception): string
    {
        $message = $this->client->redactSecret($exception->getMessage());

        return Str::limit($message, 2000, '');
    }
}
