<?php

namespace App\Jobs;

use App\Models\ManualOperation;
use App\Operations\ManualOperationCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class RunManualOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $manualOperationId)
    {
        $this->onConnection('redis-admin')->onQueue('admin');
    }

    public function handle(Kernel $console, ManualOperationCatalog $catalog): void
    {
        $claimed = ManualOperation::query()
            ->whereKey($this->manualOperationId)
            ->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $operation = ManualOperation::query()->findOrFail($this->manualOperationId);
        $definition = $catalog->find($operation->operation_key);
        if ($definition === null) {
            $this->finishFailed($operation, 'unsupported_operation', null);

            return;
        }

        try {
            $exitCode = $console->call($definition['command'], $definition['arguments'], new NullOutput);
        } catch (Throwable $exception) {
            $this->finishFailed($operation, $this->exceptionCode($exception), null);

            return;
        }

        $safeExitCode = max(0, min(255, $exitCode));
        if ($exitCode === 0) {
            $operation->update([
                'status' => 'succeeded',
                'result' => ['exit_code' => $safeExitCode],
                'error_code' => null,
                'finished_at' => now(),
            ]);

            return;
        }

        $safeExitCode = max(1, $safeExitCode);
        $this->finishFailed($operation, 'command_exit_'.$safeExitCode, $safeExitCode);
    }

    public function failed(?Throwable $exception): void
    {
        $operation = ManualOperation::query()->find($this->manualOperationId);
        if ($operation === null || ! in_array($operation->status, ['queued', 'running'], true)) {
            return;
        }

        $this->finishFailed(
            $operation,
            $exception === null ? 'job_failed' : $this->exceptionCode($exception),
            null,
        );
    }

    private function finishFailed(ManualOperation $operation, string $errorCode, ?int $exitCode): void
    {
        $operation->update([
            'status' => 'failed',
            'result' => ['exit_code' => $exitCode],
            'error_code' => $errorCode,
            'finished_at' => now(),
        ]);
    }

    private function exceptionCode(Throwable $exception): string
    {
        $class = strtolower(Str::snake(class_basename($exception)));
        $class = preg_replace('/[^a-z0-9_]+/', '_', $class) ?: 'throwable';

        return substr('exception_'.$class, 0, 120);
    }
}
