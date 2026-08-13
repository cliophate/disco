<?php

namespace App\Console\Commands;

use App\Music\Descriptions\AlbumNarrativeEnricher;
use Illuminate\Console\Command;

class EnrichAlbumNarratives extends Command
{
    protected $signature = 'disco:album-narratives
        {--scope=beyond : Album source: beyond or owned}
        {--limit=20 : Maximum eligible albums to request}
        {--force : Refresh existing descriptions}';

    protected $description = 'Cache attributed album descriptions for Beyond recommendations or the owned collection';

    public function handle(AlbumNarrativeEnricher $enricher): int
    {
        $scope = (string) $this->option('scope');
        if (! in_array($scope, ['beyond', 'owned'], true)) {
            $this->components->error('Scope must be beyond or owned.');

            return self::INVALID;
        }
        $counts = $scope === 'owned'
            ? $enricher->enrichOwned((int) $this->option('limit'), (bool) $this->option('force'))
            : $enricher->enrichLatestBeyond((int) $this->option('limit'), (bool) $this->option('force'));
        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
