<?php

namespace App\Console\Commands;

use App\Music\Discovery\BeyondLibraryMetadataEnricher;
use Illuminate\Console\Command;

class EnrichBeyondLibrary extends Command
{
    protected $signature = 'disco:beyond-enrich
        {--limit=20 : Maximum albums from the latest run}
        {--refresh : Refresh existing tracklists and artwork}
        {--retry-artwork : Retry non-ready artwork regardless of cooldown}
        {--dry-run : Report artwork coverage without provider calls or writes}
        {--json : Emit machine-readable output}';

    protected $description = 'Cache MusicBrainz tracklists and Cover Art Archive images for Beyond recommendations';

    public function handle(BeyondLibraryMetadataEnricher $enricher): int
    {
        $counts = $this->option('dry-run')
            ? $enricher->coverage((int) $this->option('limit'))
            : $enricher->enrich((int) $this->option('limit'), (bool) $this->option('refresh'), (bool) $this->option('retry-artwork'));
        $this->option('json') ? $this->line(json_encode($counts, JSON_THROW_ON_ERROR)) : $this->table(array_keys($counts), [array_values($counts)]);

        return ($counts['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
