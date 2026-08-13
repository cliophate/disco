<?php

namespace App\Console\Commands;

use App\Music\ListenBrainz\ListenBrainzImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportListenBrainz extends Command
{
    protected $signature = 'disco:listenbrainz-sync
        {--full : Scan complete ListenBrainz history and reconcile removals}
        {--max-pages=0 : Stop after this many pages; zero is unlimited}';

    protected $description = 'Import immutable listening activity from ListenBrainz';

    public function handle(ListenBrainzImporter $importer): int
    {
        $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        if ($maxPages === false || $maxPages < 0) {
            $this->error('--max-pages must be zero or greater.');

            return self::FAILURE;
        }

        try {
            $counts = $importer->import((bool) $this->option('full'), $maxPages);
        } catch (Throwable) {
            $this->error('ListenBrainz import failed. Safe details were recorded in the import run.');

            return self::FAILURE;
        }

        $this->table(
            ['Requested', 'Inserted', 'Existing', 'Matched', 'Unmatched', 'Conflicts'],
            [[
                $counts['requested'],
                $counts['inserted'],
                $counts['existing'],
                $counts['matched'],
                $counts['unmatched'],
                $counts['conflicts'],
            ]],
        );
        $this->info($counts['status'] === 'incomplete'
            ? 'ListenBrainz import stopped at the requested page limit; reconciliation was skipped.'
            : 'ListenBrainz import complete.');

        return self::SUCCESS;
    }
}
