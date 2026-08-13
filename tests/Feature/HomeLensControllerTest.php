<?php

namespace Tests\Feature;

use App\Models\User;
use App\Music\Discovery\HomeProjectionVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class HomeLensControllerTest extends TestCase
{
    public function test_lens_pages_are_bounded_version_pinned_and_deterministic(): void
    {
        $this->preparePostgres();
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $version = app(HomeProjectionVersion::class)->current($user->id, now()->toDateString());
        Cache::put("disco:home-lens:{$user->id}:{$version}:waiting", [
            'type' => 'waiting',
            'title' => 'Waiting on your shelves',
            'description' => 'Owned albums with no matched listening signal from Plex or ListenBrainz.',
            'items' => [
                $this->recommendation('album-1'),
                $this->recommendation('album-2'),
                $this->recommendation('album-3'),
            ],
        ], now()->addHour());
        $this->actingAs($user);

        $first = $this->getJson("/api/v1/home/lenses/waiting?page=1&size=2&version={$version}")
            ->assertOk()
            ->assertJsonPath('section.type', 'waiting')
            ->assertJsonPath('meta.version', $version)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.album.id', 'album-1')
            ->assertJsonPath('data.1.album.id', 'album-2');
        $this->assertStringContainsString("version={$version}", $first->json('links.next'));

        $this->getJson("/api/v1/home/lenses/waiting?page=2&size=2&version={$version}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.album.id', 'album-3')
            ->assertJsonPath('links.next', null);
        $this->getJson('/api/v1/home/lenses/beyond-library')->assertNotFound();
        $this->getJson('/api/v1/home/lenses/not-a-lens')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function recommendation(string $id): array
    {
        return [
            'album' => ['id' => $id, 'title' => $id],
            'lens' => 'Waiting on your shelves',
            'reasons' => [['code' => 'no_listen_signal', 'text' => 'No listening signal.', 'source' => 'plex']],
        ];
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
}
