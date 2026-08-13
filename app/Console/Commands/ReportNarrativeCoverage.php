<?php

namespace App\Console\Commands;

use App\Music\Descriptions\NarrativeCoverageReport;
use Illuminate\Console\Command;

class ReportNarrativeCoverage extends Command
{
    protected $signature = 'disco:narrative-coverage
        {--json : Output the report as JSON}';

    protected $description = 'Report album and artist narrative coverage without provider requests';

    public function handle(NarrativeCoverageReport $reporter): int
    {
        $report = $reporter->generate();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Entity kind', 'Eligible', 'Ready', 'Missing', 'Stale', 'Failed', 'Unattempted'],
            $report['coverage'],
        );
        $this->table(
            ['Entity kind', 'Provider', 'Language', 'Status', 'Records'],
            $report['breakdowns'],
        );

        return self::SUCCESS;
    }
}
