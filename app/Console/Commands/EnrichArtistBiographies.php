<?php

namespace App\Console\Commands;

use App\Music\Descriptions\ArtistBiographyEnricher;
use Illuminate\Console\Command;

class EnrichArtistBiographies extends Command
{
    protected $signature = 'disco:artist-biographies
        {--limit=20 : Maximum eligible artists to request}
        {--force : Refresh existing biographies}';

    protected $description = 'Cache attributed biographies for active owned artists';

    public function handle(ArtistBiographyEnricher $enricher): int
    {
        $counts = $enricher->enrichOwned((int) $this->option('limit'), (bool) $this->option('force'));
        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
