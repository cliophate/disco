<?php

namespace App\Music\MusicBrainz;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\CreditEdge;
use App\Models\ExternalIdentifier;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MusicBrainzCreditEnricher
{
    public function __construct(private readonly MusicBrainzClient $client) {}

    public function enrich(ExternalIdentifier $identifier, bool $refresh = false): int
    {
        $type = match ($identifier->namespace) {
            'musicbrainz.recording' => 'recording',
            'musicbrainz.release' => 'release',
            'musicbrainz.release_group' => 'release-group',
            'musicbrainz.work' => 'work',
            default => throw new RuntimeException('Unsupported MusicBrainz credit subject.'),
        };
        if ($identifier->status !== 'active' || $identifier->entity?->status !== 'active') {
            throw new RuntimeException('MusicBrainz credit subject is inactive.');
        }
        $object = SourceObject::query()
            ->whereHas('provider', fn ($query) => $query->where('slug', 'musicbrainz'))
            ->where('object_type', $type)
            ->where('external_id', $identifier->value)
            ->first();
        $snapshot = ! $refresh ? $object?->snapshots()
            ->where('parser_version', 'musicbrainz-credits-v1')
            ->where('expires_at', '>', now())
            ->latest('retrieved_at')
            ->first() : null;
        if ($snapshot === null) {
            $payload = $this->client->entity($type, $identifier->value);
            $snapshot = $this->snapshot($type, $identifier->value, $payload);
        } else {
            $payload = $snapshot->payload;
        }
        if (! is_array($payload)) {
            throw new RuntimeException('MusicBrainz credit snapshot is invalid.');
        }

        return DB::transaction(function () use ($identifier, $payload, $snapshot): int {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:credits:{$identifier->entity_id}"]);
            $edges = $this->edges($payload);
            CreditEdge::query()
                ->where('subject_entity_id', $identifier->entity_id)
                ->whereHas('snapshot.object.provider', fn ($query) => $query->where('slug', 'musicbrainz'))
                ->delete();
            foreach ($edges as $position => $edge) {
                $target = $this->target($edge['target_type'], $edge['target_mbid'], $edge['target_name']);
                CreditEdge::query()->create([
                    'subject_entity_id' => $identifier->entity_id,
                    'source_key' => hash('sha256', implode('|', [$edge['role'], $edge['relationship_type'], $edge['target_mbid'], $edge['credited_name'], $position])),
                    'role' => $edge['role'],
                    'credited_name' => $edge['credited_name'],
                    'target_entity_id' => $target->id,
                    'source_snapshot_id' => $snapshot->id,
                    'position' => $position,
                    'attributes' => [
                        'relationship_type' => $edge['relationship_type'],
                        'relationship_type_id' => $edge['relationship_type_id'],
                        'attributes' => $edge['attributes'],
                        'begin' => $edge['begin'],
                        'end' => $edge['end'],
                    ],
                ]);
            }

            return count($edges);
        });
    }

    /** @return list<array{role:string,relationship_type:string,relationship_type_id:?string,credited_name:string,target_type:string,target_mbid:string,target_name:string,attributes:list<string>,begin:?string,end:?string}> */
    private function edges(array $payload): array
    {
        $edges = [];
        foreach ($payload['artist-credit'] ?? [] as $credit) {
            $artist = is_array($credit) && is_array($credit['artist'] ?? null) ? $credit['artist'] : [];
            if (! $this->validTarget($artist)) {
                continue;
            }
            $edges[] = [
                'role' => 'performer',
                'relationship_type' => 'artist credit',
                'relationship_type_id' => null,
                'credited_name' => trim((string) ($credit['name'] ?? $artist['name'])),
                'target_type' => 'artist',
                'target_mbid' => strtolower($artist['id']),
                'target_name' => trim($artist['name']),
                'attributes' => [],
                'begin' => null,
                'end' => null,
            ];
        }
        foreach ($payload['relations'] ?? [] as $relation) {
            if (! is_array($relation) || ! is_string($relation['type'] ?? null)) {
                continue;
            }
            $targetType = isset($relation['artist']) ? 'artist' : (isset($relation['work']) ? 'work' : null);
            $target = $targetType === null || ! is_array($relation[$targetType] ?? null) ? [] : $relation[$targetType];
            $role = $this->role($relation['type'], $targetType);
            if ($role === null || ! $this->validTarget($target)) {
                continue;
            }
            $targetName = trim((string) ($target['name'] ?? $target['title']));
            $targetCredit = is_string($relation['target-credit'] ?? null) && trim($relation['target-credit']) !== ''
                ? trim($relation['target-credit'])
                : $targetName;
            $attributes = is_array($relation['attributes'] ?? null)
                ? array_values(array_filter($relation['attributes'], fn ($attribute): bool => is_string($attribute)))
                : [];
            $edges[] = [
                'role' => $role,
                'relationship_type' => strtolower(trim($relation['type'])),
                'relationship_type_id' => is_string($relation['type-id'] ?? null) ? strtolower($relation['type-id']) : null,
                'credited_name' => $targetCredit,
                'target_type' => $targetType,
                'target_mbid' => strtolower($target['id']),
                'target_name' => $targetName,
                'attributes' => $attributes,
                'begin' => is_string($relation['begin'] ?? null) ? $relation['begin'] : null,
                'end' => is_string($relation['end'] ?? null) ? $relation['end'] : null,
            ];
        }

        return array_slice($edges, 0, 500);
    }

    private function role(string $type, ?string $targetType): ?string
    {
        $type = strtolower($type);
        if ($targetType === 'work' && in_array($type, ['performance', 'parts'], true)) {
            return 'work';
        }
        if ($targetType !== 'artist') {
            return null;
        }
        if (in_array($type, ['producer', 'co-producer', 'executive producer'], true)) {
            return 'producer';
        }
        if (in_array($type, ['composer', 'writer', 'lyricist', 'librettist'], true)) {
            return 'songwriter';
        }
        if (in_array($type, ['engineer', 'mix', 'mastering', 'recording', 'sound'], true)) {
            return 'engineer';
        }
        if (in_array($type, ['instrument', 'vocal', 'performer', 'conductor', 'performing orchestra'], true)) {
            return 'performer';
        }

        return 'other';
    }

    private function validTarget(array $target): bool
    {
        $name = $target['name'] ?? $target['title'] ?? null;

        return is_string($target['id'] ?? null) && Str::isUuid($target['id'])
            && is_string($name) && trim($name) !== '';
    }

    private function target(string $type, string $mbid, string $name): CatalogEntity
    {
        $namespace = $type === 'artist' ? 'musicbrainz.artist' : 'musicbrainz.work';
        $kind = $type === 'artist' ? 'agent' : 'work';
        $identifier = ExternalIdentifier::query()->where('namespace', $namespace)->where('value', $mbid)->first();
        $entity = $identifier?->entity;
        for ($redirects = 0; $entity?->status === 'redirected' && $entity->redirect_entity_id !== null && $redirects < 5; $redirects++) {
            $entity = CatalogEntity::query()->find($entity->redirect_entity_id);
        }
        if ($entity !== null && $entity->kind !== $kind) {
            throw new RuntimeException('MusicBrainz credit target conflicts with the canonical catalog.');
        }
        $entity ??= CatalogEntity::query()->create([
            'kind' => $kind,
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        ExternalIdentifier::query()->updateOrCreate(
            ['namespace' => $namespace, 'value' => $mbid],
            ['entity_id' => $entity->id, 'status' => 'active'],
        );
        if ($kind === 'agent') {
            Agent::query()->firstOrCreate(['entity_id' => $entity->id], ['agent_type' => 'other']);
        }

        return $entity;
    }

    private function snapshot(string $type, string $mbid, array $payload): SourceSnapshot
    {
        $provider = SourceProvider::query()->firstOrCreate(
            ['slug' => 'musicbrainz'],
            ['display_name' => 'MusicBrainz', 'enabled' => true, 'policy' => ['storage' => 'metadata', 'connector' => 'read_only', 'license' => 'CC0']],
        );
        $now = now();
        $object = SourceObject::query()->firstOrCreate(
            ['provider_id' => $provider->id, 'object_type' => $type, 'external_id' => $mbid],
            ['canonical_url' => "https://musicbrainz.org/{$type}/{$mbid}", 'first_seen_at' => $now, 'last_seen_at' => $now],
        );
        $object->update(['last_seen_at' => $now]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $snapshot = SourceSnapshot::query()->firstOrCreate(
            ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', $encoded)],
            ['retrieved_at' => $now, 'http_status' => 200, 'payload' => $payload, 'parser_version' => 'musicbrainz-credits-v1', 'expires_at' => $now->copy()->addDays(30)],
        );
        $snapshot->update(['parser_version' => 'musicbrainz-credits-v1', 'expires_at' => $now->copy()->addDays(30)]);

        return $snapshot;
    }
}
