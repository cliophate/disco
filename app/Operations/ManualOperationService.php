<?php

namespace App\Operations;

use App\Jobs\RunManualOperation;
use App\Models\AdminAuditEntry;
use App\Models\ManualOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ManualOperationService
{
    public function __construct(private readonly ManualOperationCatalog $catalog) {}

    /** @return array{operation: ManualOperation, created: bool} */
    public function queue(string $ownerId, string $operationKey): array
    {
        $this->reconcileStale();
        $concurrencyKey = $this->catalog->concurrencyKey($ownerId, $operationKey);
        $active = $this->active($concurrencyKey);
        if ($active !== null) {
            return ['operation' => $active, 'created' => false];
        }

        $operation = null;
        try {
            DB::transaction(function () use ($concurrencyKey, $operationKey, $ownerId, &$operation): void {
                $operation = ManualOperation::query()->create([
                    'owner_user_id' => $ownerId,
                    'operation_key' => $operationKey,
                    'parameters' => (object) [],
                    'concurrency_key' => $concurrencyKey,
                    'status' => 'queued',
                ]);
                AdminAuditEntry::query()->create([
                    'owner_user_id' => $ownerId,
                    'action' => 'operation_queued',
                    'subject' => $operationKey,
                    'context' => ['manual_operation_id' => $operation->id],
                ]);

                RunManualOperation::dispatch($operation->id)->afterCommit();

            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception) || ($active = $this->active($concurrencyKey)) === null) {
                throw $exception;
            }

            return ['operation' => $active, 'created' => false];
        } catch (Throwable $exception) {
            if ($operation instanceof ManualOperation) {
                $operation->update([
                    'status' => 'failed',
                    'result' => ['exit_code' => null],
                    'error_code' => 'queue_dispatch_failed',
                    'finished_at' => now(),
                ]);
            }

            throw $exception;
        }

        assert($operation instanceof ManualOperation);

        return ['operation' => $operation, 'created' => true];
    }

    public function reconcileStale(): int
    {
        $queued = ManualOperation::query()
            ->where('status', 'queued')
            ->where('created_at', '<', now()->subMinutes(15))
            ->update([
                'status' => 'failed',
                'result' => json_encode(['exit_code' => null], JSON_THROW_ON_ERROR),
                'error_code' => 'queue_dispatch_timeout',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        $running = ManualOperation::query()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subHours(2))
            ->update([
                'status' => 'failed',
                'result' => json_encode(['exit_code' => null], JSON_THROW_ON_ERROR),
                'error_code' => 'operation_timeout',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        return $queued + $running;
    }

    /** @return list<ManualOperation> */
    public function recent(string $ownerId, int $limit = 25): array
    {
        return ManualOperation::query()
            ->where('owner_user_id', $ownerId)
            ->latest()
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(ManualOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'operation_key' => $operation->operation_key,
            'status' => $operation->status,
            'result' => $operation->result,
            'error_code' => $operation->error_code,
            'queued_at' => $operation->created_at?->toAtomString(),
            'started_at' => $operation->started_at?->toAtomString(),
            'finished_at' => $operation->finished_at?->toAtomString(),
        ];
    }

    private function active(string $concurrencyKey): ?ManualOperation
    {
        return ManualOperation::query()
            ->where('concurrency_key', $concurrencyKey)
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? $exception->getCode()) === '23505';
    }
}
