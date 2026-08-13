<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('source.entity_resolutions', 'resolution_scope')) {
            Schema::table('source.entity_resolutions', function (Blueprint $table): void {
                $table->string('resolution_scope', 32)->nullable();
            });
            DB::statement('UPDATE source.entity_resolutions AS resolutions SET resolution_scope = entities.kind FROM catalog.entities AS entities WHERE entities.id = resolutions.entity_id');
            DB::statement('ALTER TABLE source.entity_resolutions ALTER COLUMN resolution_scope SET NOT NULL');
            DB::statement('DROP INDEX source.source_one_active_resolution');
            DB::statement("CREATE UNIQUE INDEX source_one_active_resolution_per_scope ON source.entity_resolutions (source_object_id, resolution_scope) WHERE status IN ('confirmed', 'candidate')");
        }
        DB::statement('LOCK TABLE catalog.entities, catalog.release_groups, catalog.releases, catalog.external_identifiers, library.plex_entity_matches, library.holdings, source.entity_resolutions, activity.listening_event_matches, activity.play_aggregates IN SHARE ROW EXCLUSIVE MODE');
        [$canonicalGroups, $redirectedGroups] = $this->canonicalReleaseGroups();
        $this->normalizePrimaryHoldings($redirectedGroups);
        $identifiers = DB::table('catalog.external_identifiers as identifiers')
            ->join('catalog.entities as entities', 'entities.id', '=', 'identifiers.entity_id')
            ->where('identifiers.namespace', 'musicbrainz.release')
            ->where('entities.kind', 'release_group')
            ->orderBy('identifiers.id')
            ->get([
                'identifiers.id',
                'identifiers.entity_id as release_group_id',
                'identifiers.value',
                'identifiers.status',
                'entities.canonical_name',
                'entities.sort_name',
            ]);

        foreach ($identifiers as $identifier) {
            $legacyReleaseGroupId = $identifier->release_group_id;
            $releaseMbid = strtolower($identifier->value);
            $caseConflict = DB::table('catalog.external_identifiers')
                ->where('namespace', 'musicbrainz.release')
                ->where('id', '!=', $identifier->id)
                ->whereRaw('lower(value) = ?', [$releaseMbid])
                ->exists();
            if ($caseConflict) {
                throw new RuntimeException("MusicBrainz release [{$releaseMbid}] has case-duplicate identifiers.");
            }
            $releaseId = (string) Str::uuid();
            $metadata = DB::table('catalog.entity_metadata')
                ->where('entity_id', $legacyReleaseGroupId)
                ->first();
            $attributes = $metadata === null
                ? []
                : json_decode((string) $metadata->attributes, true, 512, JSON_THROW_ON_ERROR);
            $isBasisRelease = is_string($attributes['basis_release_mbid'] ?? null)
                && strtolower($attributes['basis_release_mbid']) === $releaseMbid;
            $releaseDate = $this->dateParts($isBasisRelease ? ($attributes['edition_date'] ?? null) : null);
            $now = now();

            DB::table('catalog.entities')->insert([
                'id' => $releaseId,
                'kind' => 'release',
                'status' => 'active',
                'canonical_name' => $identifier->canonical_name,
                'sort_name' => $identifier->sort_name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('catalog.releases')->insert([
                'entity_id' => $releaseId,
                'release_group_id' => $identifier->release_group_id,
                'status' => strtolower((string) ($isBasisRelease ? ($attributes['edition_status'] ?? 'unknown') : 'unknown')),
                'country_code' => $isBasisRelease ? $metadata?->country_code : null,
                'release_year' => $releaseDate['year'],
                'release_month' => $releaseDate['month'],
                'release_day' => $releaseDate['day'],
                'date_precision' => $releaseDate['precision'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('catalog.external_identifiers')
                ->where('id', $identifier->id)
                ->update(['entity_id' => $releaseId, 'value' => $releaseMbid, 'updated_at' => $now]);
            if ($isBasisRelease && $metadata !== null) {
                $releaseMetadata = (array) $metadata;
                $releaseMetadata['entity_id'] = $releaseId;
                $releaseMetadata['created_at'] = $now;
                $releaseMetadata['updated_at'] = $now;
                DB::table('catalog.entity_metadata')->insert($releaseMetadata);
            }

            $releaseGroupMbid = $attributes['release_group_mbid'] ?? null;
            if (is_string($releaseGroupMbid) && Str::isUuid($releaseGroupMbid)) {
                $releaseGroupMbid = strtolower($releaseGroupMbid);
                $releaseGroupId = $canonicalGroups[$releaseGroupMbid] ?? $legacyReleaseGroupId;
                $existingGroup = DB::table('catalog.external_identifiers')
                    ->where('namespace', 'musicbrainz.release_group')
                    ->where('value', $releaseGroupMbid)
                    ->value('entity_id');
                if ($existingGroup !== null && $existingGroup !== $releaseGroupId) {
                    throw new RuntimeException("MusicBrainz release group [{$releaseGroupMbid}] resolves to multiple catalog entities.");
                }
                if ($existingGroup === null) {
                    DB::table('catalog.external_identifiers')->insert([
                        'id' => (string) Str::uuid(),
                        'entity_id' => $releaseGroupId,
                        'namespace' => 'musicbrainz.release_group',
                        'value' => $releaseGroupMbid,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            } else {
                $releaseGroupId = $legacyReleaseGroupId;
            }

            DB::table('catalog.releases')->where('entity_id', $releaseId)->update([
                'release_group_id' => $releaseGroupId,
                'updated_at' => $now,
            ]);

            $plexItemIds = DB::table('library.plex_entity_matches as matches')
                ->join('library.plex_items as items', 'items.id', '=', 'matches.plex_item_id')
                ->join('library.plex_item_guids as guids', function ($join) use ($releaseMbid): void {
                    $join->on('guids.plex_item_id', '=', 'items.id')
                        ->where('guids.namespace', 'mbid')
                        ->whereRaw('lower(guids.value) = ?', [$releaseMbid]);
                })
                ->where('matches.entity_id', $legacyReleaseGroupId)
                ->where('matches.match_scope', 'release_group')
                ->whereIn('matches.status', ['confirmed', 'candidate'])
                ->where('items.item_type', 'album')
                ->whereRaw('(SELECT COUNT(*) FROM library.plex_item_guids AS candidate_guids WHERE candidate_guids.plex_item_id = items.id AND candidate_guids.namespace = ?) = 1', ['mbid'])
                ->when($identifier->status !== 'active', fn ($query) => $query->whereRaw('1 = 0'))
                ->pluck('items.id');

            foreach ($plexItemIds as $plexItemId) {
                $manualReleaseMatch = DB::table('library.plex_entity_matches')
                    ->where('plex_item_id', $plexItemId)
                    ->where('match_scope', 'release')
                    ->where('method', 'manual')
                    ->whereIn('status', ['confirmed', 'candidate'])
                    ->first();
                if ($manualReleaseMatch?->status === 'candidate') {
                    throw new RuntimeException('A candidate manual edition match must be confirmed or rejected before release activation.');
                }

                $selectedReleaseId = $releaseId;
                $selectedMethod = 'external_id';
                $selectedConfidence = 1;
                if ($manualReleaseMatch !== null) {
                    $selectedReleaseId = $manualReleaseMatch->entity_id;
                    $selectedMethod = 'manual';
                    $selectedConfidence = $manualReleaseMatch->confidence;
                    $manualGroupId = DB::table('catalog.releases')
                        ->where('entity_id', $selectedReleaseId)
                        ->value('release_group_id');
                    if ($manualGroupId === $legacyReleaseGroupId && $manualGroupId !== $releaseGroupId) {
                        $manualHoldingIds = DB::table('library.holdings')
                            ->where('release_id', $selectedReleaseId)
                            ->pluck('id');
                        DB::table('library.holdings')->whereIn('id', $manualHoldingIds)->update(['release_id' => null]);
                        DB::table('catalog.releases')->where('entity_id', $selectedReleaseId)->update([
                            'release_group_id' => $releaseGroupId,
                            'updated_at' => $now,
                        ]);
                        DB::table('library.holdings')->whereIn('id', $manualHoldingIds)->update([
                            'release_group_id' => $releaseGroupId,
                            'release_id' => $selectedReleaseId,
                            'updated_at' => $now,
                        ]);
                    } elseif ($manualGroupId !== $releaseGroupId) {
                        throw new RuntimeException('A manual edition match conflicts with its canonical release group.');
                    }
                } else {
                    DB::table('library.plex_entity_matches')
                        ->where('plex_item_id', $plexItemId)
                        ->where('match_scope', 'release')
                        ->whereIn('status', ['confirmed', 'candidate'])
                        ->update(['status' => 'superseded', 'updated_at' => $now]);
                    DB::table('library.plex_entity_matches')->insert([
                        'id' => (string) Str::uuid(),
                        'plex_item_id' => $plexItemId,
                        'entity_id' => $releaseId,
                        'match_scope' => 'release',
                        'status' => 'confirmed',
                        'method' => 'external_id',
                        'confidence' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                DB::table('library.holdings')
                    ->where('plex_album_item_id', $plexItemId)
                    ->whereIn('release_group_id', [$legacyReleaseGroupId, $releaseGroupId])
                    ->update(['release_group_id' => $releaseGroupId, 'release_id' => $selectedReleaseId, 'updated_at' => $now]);

                $plexObject = DB::table('library.plex_items as items')
                    ->join('library.plex_libraries as libraries', 'libraries.id', '=', 'items.plex_library_id')
                    ->where('items.id', $plexItemId)
                    ->first(['libraries.plex_server_id', 'libraries.section_key', 'items.rating_key']);
                $sourceObjectId = $plexObject === null
                    ? null
                    : DB::table('source.objects as objects')
                        ->join('source.providers as providers', 'providers.id', '=', 'objects.provider_id')
                        ->where('providers.slug', 'plex')
                        ->where('objects.object_type', 'album')
                        ->where('objects.external_id', "{$plexObject->plex_server_id}:{$plexObject->section_key}:{$plexObject->rating_key}")
                        ->value('objects.id');
                if ($sourceObjectId !== null) {
                    $this->recordReleaseResolution(
                        $sourceObjectId,
                        $selectedReleaseId,
                        $plexItemId,
                        $selectedMethod,
                        $selectedConfidence,
                        $now,
                    );
                }
            }
        }

        $this->redirectReleaseGroups($redirectedGroups, now());
    }

    private function recordReleaseResolution(
        string $sourceObjectId,
        string $releaseId,
        string $plexItemId,
        string $method,
        mixed $confidence,
        mixed $now,
    ): void {
        DB::table('source.entity_resolutions')
            ->where('source_object_id', $sourceObjectId)
            ->where('resolution_scope', 'release')
            ->where('entity_id', '!=', $releaseId)
            ->whereIn('status', ['confirmed', 'candidate'])
            ->update(['status' => 'superseded', 'updated_at' => $now]);
        $resolution = DB::table('source.entity_resolutions')
            ->where('source_object_id', $sourceObjectId)
            ->where('entity_id', $releaseId)
            ->where('resolution_scope', 'release')
            ->orderByRaw("CASE WHEN status IN ('confirmed', 'candidate') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
        if ($resolution !== null) {
            DB::table('source.entity_resolutions')
                ->where('source_object_id', $sourceObjectId)
                ->where('resolution_scope', 'release')
                ->where('id', '!=', $resolution->id)
                ->whereIn('status', ['confirmed', 'candidate'])
                ->update(['status' => 'superseded', 'updated_at' => $now]);
        }
        $values = [
            'status' => 'confirmed',
            'method' => $method,
            'confidence' => $confidence,
            'algorithm_version' => 'plex-v2',
            'evidence' => json_encode(['plex_item_id' => $plexItemId, 'activated_from_legacy' => true], JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];
        if ($resolution === null) {
            DB::table('source.entity_resolutions')->insert($values + [
                'id' => (string) Str::uuid(),
                'source_object_id' => $sourceObjectId,
                'entity_id' => $releaseId,
                'resolution_scope' => 'release',
                'created_at' => $now,
            ]);
        } else {
            DB::table('source.entity_resolutions')->where('id', $resolution->id)->update($values);
        }
    }

    /** @return array{0:array<string, string>,1:array<string, string>} */
    private function canonicalReleaseGroups(): array
    {
        $groups = DB::table('catalog.entity_metadata as metadata')
            ->join('catalog.entities as entities', 'entities.id', '=', 'metadata.entity_id')
            ->join('catalog.external_identifiers as identifiers', function ($join): void {
                $join->on('identifiers.entity_id', '=', 'entities.id')
                    ->where('identifiers.namespace', 'musicbrainz.release');
            })
            ->where('entities.kind', 'release_group')
            ->select('entities.id', DB::raw("lower(metadata.attributes->>'release_group_mbid') as release_group_mbid"))
            ->distinct()
            ->get()
            ->filter(fn (object $group): bool => Str::isUuid((string) $group->release_group_mbid))
            ->groupBy('release_group_mbid');

        $canonicalGroups = [];
        $redirectedGroups = [];
        foreach ($groups as $releaseGroupMbid => $candidates) {
            $candidateIds = $candidates->pluck('id')->all();
            $manualGroupIds = DB::table('library.plex_entity_matches')
                ->whereIn('entity_id', $candidateIds)
                ->where('match_scope', 'release_group')
                ->where('method', 'manual')
                ->where('status', 'confirmed')
                ->pluck('entity_id')
                ->flip();
            $activeHoldings = DB::table('library.holdings as holdings')
                ->join('library.plex_items as items', 'items.id', '=', 'holdings.plex_album_item_id')
                ->whereIn('holdings.release_group_id', $candidateIds)
                ->whereNull('items.removed_at')
                ->select('holdings.release_group_id', 'holdings.is_primary_playback_copy')
                ->get()
                ->groupBy('release_group_id');

            usort($candidateIds, function (string $left, string $right) use ($activeHoldings, $manualGroupIds): int {
                $rank = static fn (string $id): array => [
                    $activeHoldings->get($id)?->contains('is_primary_playback_copy', true) ? 0 : 1,
                    $activeHoldings->has($id) ? 0 : 1,
                    $manualGroupIds->has($id) ? 0 : 1,
                    $id,
                ];

                return $rank($left) <=> $rank($right);
            });

            $canonicalGroupId = $candidateIds[0];
            $canonicalGroups[(string) $releaseGroupMbid] = $canonicalGroupId;
            foreach (array_slice($candidateIds, 1) as $groupId) {
                $redirectedGroups[$groupId] = $canonicalGroupId;
            }
        }

        return [$canonicalGroups, $redirectedGroups];
    }

    /** @param array<string, string> $redirectedGroups */
    private function normalizePrimaryHoldings(array $redirectedGroups): void
    {
        foreach (collect($redirectedGroups)->groupBy(fn (string $canonicalGroupId): string => $canonicalGroupId, true) as $canonicalGroupId => $groupIds) {
            $mergeGroupIds = $groupIds->keys()->push($canonicalGroupId)->all();
            $primaryHoldingId = DB::table('library.holdings as holdings')
                ->join('library.plex_items as items', 'items.id', '=', 'holdings.plex_album_item_id')
                ->whereIn('release_group_id', $mergeGroupIds)
                ->whereNull('items.removed_at')
                ->orderByRaw('CASE WHEN release_group_id = ? THEN 0 ELSE 1 END', [$canonicalGroupId])
                ->orderByDesc('holdings.is_primary_playback_copy')
                ->orderBy('holdings.id')
                ->value('holdings.id');
            DB::table('library.holdings')
                ->whereIn('release_group_id', $mergeGroupIds)
                ->where('is_primary_playback_copy', true)
                ->update(['is_primary_playback_copy' => false]);
            if ($primaryHoldingId !== null) {
                DB::table('library.holdings')
                    ->where('id', $primaryHoldingId)
                    ->update(['is_primary_playback_copy' => true]);
            }
        }
    }

    /** @param array<string, string> $redirectedGroups */
    private function redirectReleaseGroups(array $redirectedGroups, mixed $now): void
    {
        foreach ($redirectedGroups as $groupId => $canonicalGroupId) {
            $releaseIds = DB::table('catalog.releases')
                ->where('release_group_id', $groupId)
                ->pluck('entity_id');
            $releaseHoldings = DB::table('library.holdings')
                ->whereIn('release_id', $releaseIds)
                ->get(['id', 'release_id']);
            DB::table('library.holdings')
                ->whereIn('id', $releaseHoldings->pluck('id'))
                ->update(['release_id' => null]);
            DB::table('catalog.releases')
                ->whereIn('entity_id', $releaseIds)
                ->update(['release_group_id' => $canonicalGroupId, 'updated_at' => $now]);
            foreach ($releaseHoldings as $holding) {
                DB::table('library.holdings')->where('id', $holding->id)->update([
                    'release_group_id' => $canonicalGroupId,
                    'release_id' => $holding->release_id,
                    'updated_at' => $now,
                ]);
            }

            if (DB::table('library.holdings')
                ->where('release_group_id', $canonicalGroupId)
                ->where('is_primary_playback_copy', true)
                ->exists()) {
                DB::table('library.holdings')
                    ->where('release_group_id', $groupId)
                    ->update(['is_primary_playback_copy' => false]);
            }
            DB::table('library.holdings')
                ->where('release_group_id', $groupId)
                ->update(['release_group_id' => $canonicalGroupId, 'updated_at' => $now]);

            $matches = DB::table('library.plex_entity_matches')
                ->where('entity_id', $groupId)
                ->where('match_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->get();
            foreach ($matches as $match) {
                DB::table('library.plex_entity_matches')->where('id', $match->id)->update([
                    'status' => 'superseded',
                    'updated_at' => $now,
                ]);
                $targetMatch = DB::table('library.plex_entity_matches')
                    ->where('plex_item_id', $match->plex_item_id)
                    ->where('entity_id', $canonicalGroupId)
                    ->where('match_scope', 'release_group')
                    ->first();
                $values = [
                    'status' => $match->status,
                    'method' => $match->method,
                    'confidence' => $match->confidence,
                    'updated_at' => $now,
                ];
                if ($targetMatch === null) {
                    DB::table('library.plex_entity_matches')->insert($values + [
                        'id' => (string) Str::uuid(),
                        'plex_item_id' => $match->plex_item_id,
                        'entity_id' => $canonicalGroupId,
                        'match_scope' => 'release_group',
                        'created_at' => $now,
                    ]);
                } else {
                    if ($targetMatch->method === 'manual' && $match->method !== 'manual') {
                        $values = [
                            'status' => 'confirmed',
                            'method' => 'manual',
                            'confidence' => 1,
                            'updated_at' => $now,
                        ];
                    }
                    DB::table('library.plex_entity_matches')->where('id', $targetMatch->id)->update($values);
                }
            }

            $resolutions = DB::table('source.entity_resolutions')
                ->where('entity_id', $groupId)
                ->where('resolution_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->get();
            foreach ($resolutions as $resolution) {
                $targetResolution = DB::table('source.entity_resolutions')
                    ->where('source_object_id', $resolution->source_object_id)
                    ->where('entity_id', $canonicalGroupId)
                    ->where('resolution_scope', 'release_group')
                    ->first();
                if ($targetResolution === null) {
                    DB::table('source.entity_resolutions')->where('id', $resolution->id)->update([
                        'entity_id' => $canonicalGroupId,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('source.entity_resolutions')->where('id', $resolution->id)->update([
                        'status' => 'superseded',
                        'updated_at' => $now,
                    ]);
                    DB::table('source.entity_resolutions')->where('id', $targetResolution->id)->update([
                        'status' => $resolution->status,
                        'method' => $resolution->method,
                        'confidence' => $resolution->confidence,
                        'algorithm_version' => $resolution->algorithm_version,
                        'evidence' => $resolution->evidence,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('activity.listening_event_matches')
                ->where('release_group_entity_id', $groupId)
                ->update(['release_group_entity_id' => $canonicalGroupId, 'updated_at' => $now]);
            DB::table('catalog.entities')->where('id', $groupId)->update([
                'status' => 'redirected',
                'redirect_entity_id' => $canonicalGroupId,
                'updated_at' => $now,
            ]);
        }

        if ($redirectedGroups !== []) {
            DB::statement('DELETE FROM activity.play_aggregates');
            DB::statement(<<<'SQL'
                INSERT INTO activity.play_aggregates (
                    release_group_entity_id, play_count, first_listened_at, last_listened_at, created_at, updated_at
                )
                SELECT matches.release_group_entity_id, COUNT(*), MIN(events.listened_at), MAX(events.listened_at),
                       CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM activity.listening_event_matches AS matches
                JOIN activity.listening_events AS events ON events.id = matches.listening_event_id
                JOIN source.accounts AS accounts ON accounts.id = events.source_account_id
                WHERE matches.source_present = true
                  AND matches.status = 'matched'
                  AND matches.release_group_entity_id IS NOT NULL
                  AND accounts.status = 'active'
                GROUP BY matches.release_group_entity_id
            SQL);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Release edition activation is an irreversible canonical identity migration.');
    }

    /** @return array{year:?int,month:?int,day:?int,precision:string} */
    private function dateParts(mixed $value): array
    {
        $parts = is_string($value) && preg_match('/\A([0-9]{1,4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?\z/', $value, $matches) === 1
            ? $matches
            : [];

        return [
            'year' => isset($parts[1]) ? (int) $parts[1] : null,
            'month' => isset($parts[2]) ? (int) $parts[2] : null,
            'day' => isset($parts[3]) ? (int) $parts[3] : null,
            'precision' => isset($parts[3]) ? 'day' : (isset($parts[2]) ? 'month' : (isset($parts[1]) ? 'year' : 'unknown')),
        ];
    }
};
