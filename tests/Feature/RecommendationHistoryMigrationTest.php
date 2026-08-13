<?php

namespace Tests\Feature;

use App\Models\HomeEdition;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RecommendationHistoryMigrationTest extends TestCase
{
    public function test_legacy_cached_editions_are_regenerated_with_complete_recommendation_runs(): void
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
        $rollbackSteps = DB::table('migrations')->where('migration', '>=', '2026_07_22_000700_create_recommendation_history')->count();
        Artisan::call('migrate:rollback', ['--step' => $rollbackSteps, '--force' => true]);
        $owner = User::query()->create([
            'name' => 'Fixture Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('fixture-password'),
        ]);
        HomeEdition::query()->create([
            'version_hash' => str_repeat('a', 64),
            'algorithm_version' => 'owned-listening-lenses-v2',
            'facts_as_of' => now(),
            'payload' => ['feature' => null, 'sections' => [], 'recent_artists' => [], 'collection' => [], 'meta' => []],
            'generated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, DB::table('discovery.home_editions')->count());
        $this->actingAs($owner);
        $response = $this->getJson('/api/v1/home')->assertOk();
        $this->assertNotNull($response->json('meta.edition_id'));
        $this->assertSame(1, DB::table('discovery.home_editions')->count());
        $this->assertSame(1, DB::table('discovery.recommendation_runs')->count());
        $this->assertNotNull(DB::table('discovery.home_editions')->value('recommendation_run_id'));
        $this->assertSame($owner->id, DB::table('discovery.home_editions')->value('user_id'));
    }
}
