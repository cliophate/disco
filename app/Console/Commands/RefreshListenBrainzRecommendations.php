<?php

namespace App\Console\Commands;

use App\Music\Discovery\BeyondLibraryMetadataEnricher;
use App\Music\Discovery\ListenBrainzRecommendationRefresher;
use Illuminate\Console\Command;

class RefreshListenBrainzRecommendations extends Command
{
    protected $signature = 'disco:listenbrainz-recommendations
        {--count= : Recording recommendations to request}
        {--limit= : External releases to retain}';

    protected $description = 'Refresh beyond-library release recommendations from ListenBrainz recordings';

    public function handle(ListenBrainzRecommendationRefresher $refresher, BeyondLibraryMetadataEnricher $metadata): int
    {
        $result = $refresher->refresh(
            $this->option('count') === null ? null : (int) $this->option('count'),
            $this->option('limit') === null ? null : (int) $this->option('limit'),
        );
        $this->table(['Status', 'Candidates', 'Recordings', 'Reused'], [[
            $result['status'],
            $result['candidates'],
            $result['recordings'],
            $result['reused'] ? 'yes' : 'no',
        ]]);
        $metadataCounts = $metadata->enrich(min(50, max(1, $result['candidates'])));
        $this->table(array_keys($metadataCounts), [array_values($metadataCounts)]);

        return self::SUCCESS;
    }
}
