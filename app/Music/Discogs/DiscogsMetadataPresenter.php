<?php

namespace App\Music\Discogs;

use Illuminate\Support\Facades\DB;

class DiscogsMetadataPresenter
{
    public function __construct(private readonly DiscogsClient $discogs) {}

    /** @return array<string, mixed>|null */
    public function forEntity(string $entityId): ?array
    {
        if (! $this->discogs->configured()) {
            return null;
        }
        $snapshot = DB::table('source.entity_resolutions as resolutions')
            ->join('source.objects as objects', 'objects.id', '=', 'resolutions.source_object_id')
            ->join('source.providers as providers', 'providers.id', '=', 'objects.provider_id')
            ->join('source.snapshots as snapshots', 'snapshots.source_object_id', '=', 'objects.id')
            ->where('resolutions.entity_id', $entityId)
            ->where('resolutions.status', 'confirmed')
            ->where('providers.slug', 'discogs')
            ->where('providers.enabled', true)
            ->where('snapshots.http_status', 200)
            ->where('snapshots.expires_at', '>', now())
            ->orderByDesc('snapshots.retrieved_at')
            ->first(['objects.object_type', 'objects.external_id', 'objects.canonical_url', 'snapshots.payload', 'snapshots.retrieved_at']);
        if ($snapshot === null) {
            return null;
        }
        $payload = json_decode((string) $snapshot->payload, true, 256, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            return null;
        }

        return [
            'object_type' => $snapshot->object_type,
            'external_id' => $snapshot->external_id,
            'source_url' => $snapshot->canonical_url,
            'fetched_at' => $snapshot->retrieved_at,
            'fields' => $payload,
        ];
    }
}
