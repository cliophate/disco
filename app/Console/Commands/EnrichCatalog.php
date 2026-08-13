<?php

namespace App\Console\Commands;

use App\Music\Discovery\CatalogEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class EnrichCatalog extends Command
{
    protected $signature = 'disco:catalog-enrich
        {--limit=50 : Maximum release groups to enrich}
        {--refresh : Refresh detail and artwork regardless of freshness}
        {--retry-artwork : Retry non-ready artwork regardless of cooldown}
        {--json : Emit machine-readable output}';

    protected $description = 'Drain missing metadata and artwork for current discovery surfaces';

    public function handle(CatalogEnrichmentService $enricher): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 100) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }

        $lock = Cache::lock('disco:catalog-enrich', 14400);
        if (! $lock->get()) {
            $this->warn('Another catalog enrichment is already running.');

            return self::FAILURE;
        }

        try {
            $counts = $enricher->enrich($limit, (bool) $this->option('refresh'), (bool) $this->option('retry-artwork'));
            $this->option('json')
                ? $this->line(json_encode($counts, JSON_THROW_ON_ERROR))
                : $this->table(array_keys($counts), [array_values($counts)]);

            return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
