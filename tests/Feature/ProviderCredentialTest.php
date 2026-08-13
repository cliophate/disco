<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ProviderCredentialController;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Music\Admin\ProviderCredentialResolver;
use App\Music\Admin\ProviderCredentialTester;
use App\Music\ListenBrainz\ListenBrainzClient;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProviderCredentialTest extends TestCase
{
    public function test_encrypted_overrides_are_activated_only_after_a_successful_owner_test(): void
    {
        $this->preparePostgres();
        config()->set('services.discogs.token', 'environment-token');
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('current-password'),
        ]);
        ProviderCredential::query()->create([
            'provider' => 'discogs',
            'credentials' => ['token' => 'current-database-token'],
            'tested_at' => now()->subDay(),
        ]);
        $tester = Mockery::mock(ProviderCredentialTester::class);
        $tester->expects('test')->once()->with('discogs', 'rejected-token')->andThrow(new RuntimeException('rejected'));
        $tester->expects('test')->once()->with('discogs', 'accepted-token');
        $controller = app(ProviderCredentialController::class);
        $resolver = app(ProviderCredentialResolver::class);

        try {
            $controller->update(
                $this->request($owner, ['current_password' => 'current-password', 'secret' => 'rejected-token']),
                'discogs',
                $resolver,
                $tester,
            );
            $this->fail('A failed provider test must reject activation.');
        } catch (ValidationException) {
            $this->assertSame('current-database-token', ProviderCredential::query()->findOrFail('discogs')->credentials['token']);
        }

        $response = $controller->update(
            $this->request($owner, ['current_password' => 'current-password', 'secret' => 'accepted-token']),
            'discogs',
            $resolver,
            $tester,
        );

        $this->assertSame('database', $response->getData(true)['data']['source']);
        $this->assertArrayNotHasKey('secret', $response->getData(true)['data']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('accepted-token', $resolver->resolve('discogs')['secret']);
        $stored = (string) DB::table('app.provider_credentials')->where('provider', 'discogs')->value('credentials');
        $this->assertStringNotContainsString('accepted-token', $stored);
        $this->assertDatabaseHas('app.admin_audit_entries', [
            'owner_user_id' => $owner->id,
            'action' => 'credential_activated',
            'subject' => 'discogs',
        ]);
        $auditContext = (string) DB::table('app.admin_audit_entries')->where('action', 'credential_activated')->value('context');
        $this->assertStringNotContainsString('accepted-token', $auditContext);
        ProviderCredential::query()->create([
            'provider' => 'listenbrainz',
            'credentials' => ['token' => 'database-listen-token'],
            'tested_at' => now(),
        ]);
        $this->assertSame('request failed for [redacted]', app(ListenBrainzClient::class)->redactSecret('request failed for database-listen-token'));

        $delete = $controller->destroy(
            $this->request($owner, ['current_password' => 'current-password']),
            'discogs',
            $resolver,
        );
        $this->assertSame([
            'provider' => 'discogs',
            'configured' => true,
            'source' => 'environment',
            'tested_at' => null,
        ], $delete->getData(true)['data']);
        $this->assertDatabaseHas('app.admin_audit_entries', [
            'owner_user_id' => $owner->id,
            'action' => 'credential_removed',
            'subject' => 'discogs',
        ]);
    }

    public function test_absent_table_falls_back_but_corrupt_ciphertext_fails_closed(): void
    {
        $this->preparePostgres();
        config()->set('services.plex.token', 'environment-token');
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('current-password'),
        ]);
        $resolver = app(ProviderCredentialResolver::class);

        DB::table('app.provider_credentials')->insert([
            'provider' => 'plex',
            'credentials' => 'not-valid-ciphertext',
            'tested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            $resolver->resolve('plex');
            $this->fail('Corrupt ciphertext must not fall back to the environment.');
        } catch (DecryptException) {
            $this->assertTrue(true);
        }

        $this->actingAs($owner)->getJson('/api/v1/admin/providers')
            ->assertOk()
            ->assertJsonPath('data.0.provider', 'plex')
            ->assertJsonPath('data.0.configured', false)
            ->assertJsonPath('data.0.source', 'unreadable')
            ->assertJsonMissing(['secret' => 'environment-token']);
        $this->deleteJson('/api/v1/admin/providers/plex', ['current_password' => 'current-password'])
            ->assertOk()
            ->assertJsonPath('data.source', 'environment');

        DB::statement('DROP TABLE app.provider_credentials');
        $this->assertSame('environment-token', $resolver->resolve('plex')['secret']);
        $this->assertSame('environment', $resolver->resolve('plex')['source']);
    }

    private function preparePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration test; use compose.test.yaml.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than the dedicated disco_test database.');
        }

        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    /** @param array<string, string> $data */
    private function request(User $owner, array $data): Request
    {
        $request = Request::create('/provider-credentials', 'PUT', $data);
        $request->setUserResolver(fn (): User => $owner);

        return $request;
    }
}
