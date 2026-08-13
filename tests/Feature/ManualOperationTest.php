<?php

namespace Tests\Feature;

use App\Admin\AdminOverviewService;
use App\Jobs\RunManualOperation;
use App\Models\ManualOperation;
use App\Models\User;
use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Metadata\PipelineStatusService;
use App\Operations\ManualOperationCatalog;
use App\Operations\ManualOperationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class ManualOperationTest extends TestCase
{
    public function test_owner_can_queue_an_allowlisted_operation_and_duplicates_conflict(): void
    {
        $this->preparePostgres();
        Queue::fake();
        $owner = $this->owner();

        $this->postJson('/api/v1/admin/operations/listenbrainz.import')->assertUnauthorized();
        $response = $this->actingAs($owner)->postJson('/api/v1/admin/operations/listenbrainz.import')->assertAccepted()
            ->assertJsonPath('data.operation_key', 'listenbrainz.import')
            ->assertJsonPath('data.status', 'queued');

        $id = $response->json('data.id');
        Queue::assertPushed(RunManualOperation::class, fn (RunManualOperation $job): bool => $job->manualOperationId === $id
            && $job->connection === 'redis-admin' && $job->queue === 'admin');
        $this->assertSame([], ManualOperation::query()->findOrFail($id)->parameters);
        $this->assertDatabaseHas('app.admin_audit_entries', [
            'owner_user_id' => $owner->id,
            'action' => 'operation_queued',
            'subject' => 'listenbrainz.import',
        ]);

        $this->postJson('/api/v1/admin/operations/listenbrainz.import')
            ->assertConflict()
            ->assertJsonPath('code', 'operation_in_progress')
            ->assertJsonPath('data.id', $id);
        Queue::assertPushed(RunManualOperation::class, 1);
        $this->assertDatabaseCount('app.admin_audit_entries', 1);

        $this->getJson('/api/v1/admin/operations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(9, 'meta.available_operations');
        $this->postJson('/api/v1/admin/operations/catalog.enrich')->assertAccepted()
            ->assertJsonPath('data.operation_key', 'catalog.enrich');
        $this->assertDatabaseHas('app.manual_operations', ['operation_key' => 'catalog.enrich']);
        $this->postJson('/api/v1/admin/operations/plex.sync', [
            'options' => ['--dry-run' => true],
        ])->assertUnprocessable();
    }

    public function test_job_uses_only_fixed_arguments_and_persists_safe_results(): void
    {
        $this->preparePostgres();
        $owner = $this->owner();
        $catalog = new ManualOperationCatalog;
        $operation = $this->operation($owner, 'listenbrainz.recommendations');
        $console = Mockery::mock(Kernel::class);
        $console->shouldReceive('call')->once()->with(
            'disco:listenbrainz-recommendations',
            ['--count' => 250, '--limit' => 25],
            Mockery::type(NullOutput::class),
        )->andReturn(0);

        (new RunManualOperation($operation->id))->handle($console, $catalog);

        $operation->refresh();
        $this->assertSame('succeeded', $operation->status);
        $this->assertSame(['exit_code' => 0], $operation->result);
        $this->assertNull($operation->error_code);

        $failed = $this->operation($owner, 'musicbrainz.enrich');
        $console = Mockery::mock(Kernel::class);
        $console->shouldReceive('call')->once()->andThrow(new RuntimeException('token=must-not-be-persisted'));

        (new RunManualOperation($failed->id))->handle($console, $catalog);

        $failed->refresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('exception_runtime_exception', $failed->error_code);
        $this->assertStringNotContainsString('must-not-be-persisted', json_encode($failed->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_admin_overview_uses_safe_provider_statuses_without_provider_calls(): void
    {
        $this->preparePostgres();
        $owner = $this->owner();
        $this->operation($owner, 'plex.sync');
        DB::table('failed_jobs')->insert([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'safe fixture',
            'failed_at' => now(),
        ]);
        $pipelines = Mockery::mock(PipelineStatusService::class);
        $pipelines->shouldReceive('summarize')->once()->andReturn([['key' => 'plex', 'status' => 'healthy']]);
        $providers = Mockery::mock(ProviderCredentialResolver::class);
        $providers->shouldReceive('statuses')->once()->andReturn([['provider' => 'plex', 'configured' => true, 'source' => 'environment', 'tested_at' => null]]);
        $service = new AdminOverviewService($pipelines, app(ManualOperationService::class), $providers);

        $overview = $service->summarize($owner->id);

        $this->assertSame([['key' => 'plex', 'status' => 'healthy']], $overview['pipelines']);
        $this->assertCount(1, $overview['recent_operations']);
        $this->assertSame(1, $overview['failed_jobs_count']);
        $this->assertSame('plex', $overview['providers'][0]['provider']);
    }

    public function test_stale_operations_are_failed_and_release_their_concurrency_slots(): void
    {
        $this->preparePostgres();
        Queue::fake();
        $owner = $this->owner();
        $queued = $this->operation($owner, 'plex.sync');
        $queued->forceFill(['created_at' => now()->subMinutes(16), 'updated_at' => now()->subMinutes(16)])->save();
        $running = $this->operation($owner, 'musicbrainz.enrich');
        $running->update(['status' => 'running', 'started_at' => now()->subHours(3)]);

        $operations = app(ManualOperationService::class);
        $this->assertSame(2, $operations->reconcileStale());
        $this->assertSame('queue_dispatch_timeout', $queued->refresh()->error_code);
        $this->assertSame('operation_timeout', $running->refresh()->error_code);

        $replacement = $operations->queue($owner->id, 'plex.sync');
        $this->assertTrue($replacement['created']);
        $this->assertNotSame($queued->id, $replacement['operation']->id);
    }

    private function operation(User $owner, string $key): ManualOperation
    {
        return ManualOperation::query()->create([
            'owner_user_id' => $owner->id,
            'operation_key' => $key,
            'parameters' => (object) [],
            'concurrency_key' => $owner->id.':'.$key,
            'status' => 'queued',
        ]);
    }

    private function owner(): User
    {
        return User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture'),
        ]);
    }

    private function preparePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration test; use compose.test.yaml.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than disco_test.');
        }
        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
