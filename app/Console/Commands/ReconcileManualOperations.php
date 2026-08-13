<?php

namespace App\Console\Commands;

use App\Operations\ManualOperationService;
use Illuminate\Console\Command;

class ReconcileManualOperations extends Command
{
    protected $signature = 'disco:manual-operations-reconcile';

    protected $description = 'Fail stale owner operations so they can be safely requested again';

    public function handle(ManualOperationService $operations): int
    {
        $this->info($operations->reconcileStale().' stale manual operation(s) reconciled.');

        return self::SUCCESS;
    }
}
