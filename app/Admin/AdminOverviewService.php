<?php

namespace App\Admin;

use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Metadata\PipelineStatusService;
use App\Operations\ManualOperationService;
use Illuminate\Support\Facades\DB;

class AdminOverviewService
{
    public function __construct(
        private readonly PipelineStatusService $pipelines,
        private readonly ManualOperationService $operations,
        private readonly ProviderCredentialResolver $providers,
    ) {}

    /** @return array<string, mixed> */
    public function summarize(string $ownerId): array
    {
        return [
            'pipelines' => $this->pipelines->summarize(),
            'recent_operations' => collect($this->operations->recent($ownerId))
                ->map(fn ($operation): array => $this->operations->present($operation))
                ->values(),
            'failed_jobs_count' => DB::table('failed_jobs')->count(),
            'providers' => $this->providers->statuses(),
        ];
    }
}
