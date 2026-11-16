<?php

namespace App\Admin;

use App\Models\DiscoveryBatch;
use App\Models\ListenImportRun;
use App\Models\PlexSyncRun;
use App\Music\Admin\ProviderCredentialResolver;

class AdminOverviewService
{
    public function __construct(private readonly ProviderCredentialResolver $providers) {}

    public function summarize(string $ownerId): array
    {
        $plex = PlexSyncRun::query()->latest('started_at')->first();
        $listens = ListenImportRun::query()->latest('started_at')->first();
        $pipelines = [
            ['name' => 'Plex sync', 'status' => $plex?->status ?? 'Never run', 'completed_at' => $plex?->completed_at?->toIso8601String()],
            ['name' => 'ListenBrainz import', 'status' => $listens?->status ?? 'Never run', 'completed_at' => $listens?->completed_at?->toIso8601String()],
        ];
        foreach (['new_artists' => 'New artists', 'new_releases' => 'New releases', 'more' => 'More from artists you play'] as $section => $name) {
            $batch = DiscoveryBatch::query()->where('section', $section)->latest('period')->first();
            $pipelines[] = ['name' => $name, 'status' => $batch?->status ?? 'Never run', 'completed_at' => $batch?->completed_at?->toIso8601String(), 'summary' => $batch?->summary];
        }

        return ['pipelines' => $pipelines, 'providers' => $this->providers->statuses()];
    }
}
