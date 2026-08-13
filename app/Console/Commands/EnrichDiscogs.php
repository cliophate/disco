<?php

namespace App\Console\Commands;

use App\Music\Discogs\DiscogsEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class EnrichDiscogs extends Command
{
    protected $signature = 'disco:discogs-enrich
        {--limit=20 : Maximum exact owned artists and albums to inspect}
        {--force : Bypass provider cooldowns while retaining the limit}
        {--dry-run : Contact providers and report matches without writing}';

    protected $description = 'Enrich exact owned artists and albums with approved Discogs catalog fields';

    public function handle(DiscogsEnricher $enricher): int
    {
        $lock = Cache::lock('disco:discogs-enrich', 3900);
        if (! $lock->get()) {
            $this->warn('Another Discogs enrichment is already running.');

            return self::FAILURE;
        }

        try {
            return $this->enrich($enricher);
        } finally {
            $lock->release();
        }
    }

    private function enrich(DiscogsEnricher $enricher): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 100) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }
        $before = $enricher->coverage();
        $counts = $enricher->enrichOwned($limit, (bool) $this->option('force'), (bool) $this->option('dry-run'));
        $after = $enricher->coverage();
        $this->table(
            ['Enabled', 'Requested', 'Matched', 'Refreshed', 'Missing', 'Ambiguous', 'Conflicts', 'Failed', 'MB requests', 'Discogs requests', 'Restricted dropped'],
            [[
                $counts['enabled'] ? 'yes' : 'no', $counts['requested'], $counts['matched'], $counts['refreshed'],
                $counts['missing'], $counts['ambiguous'], $counts['conflicts'], $counts['failed'],
                $counts['musicbrainz_requests'], $counts['discogs_requests'], $counts['restricted_fields_dropped'],
            ]],
        );
        $this->table(
            ['Coverage', 'Eligible', 'Identified', 'Fresh', 'Stale hidden', 'Restricted snapshots'],
            [
                ['Before', $before['eligible'], $before['identified'], $before['fresh'], $before['stale'], $before['restricted_snapshots']],
                ['After', $after['eligible'], $after['identified'], $after['fresh'], $after['stale'], $after['restricted_snapshots']],
            ],
        );
        if (! $counts['enabled']) {
            $this->warn('Discogs enrichment is disabled because DISCOGS_TOKEN is not configured.');
        }

        return $counts['failed'] === 0 && $after['restricted_snapshots'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
