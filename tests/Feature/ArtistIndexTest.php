<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ArtistIndexTest extends TestCase
{
    public function test_artist_index_is_stable_paginated_filterable_and_count_aware(): void
    {
        $this->preparePostgres();
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $library = $this->createLibrary();
        $alpha = $this->createArtist($library, 'Alpha Person', 'Person');
        $beta = $this->createArtist($library, 'beta Group', 'Group');
        $choir = $this->createArtist($library, 'Choir Artist', 'Choir');
        $other = $this->createArtist($library, 'Zulu Unknown', null);
        $this->actingAs($user);

        $first = $this->getJson('/api/v1/artists?page=1&size=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.1.id', $beta->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.filters.all', 4)
            ->assertJsonPath('meta.filters.person', 1)
            ->assertJsonPath('meta.filters.group', 2)
            ->assertJsonPath('meta.filters.other', 1);
        $this->assertNotNull($first->json('links.next'));

        $this->getJson('/api/v1/artists?page=2&size=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $choir->id)
            ->assertJsonPath('data.1.id', $other->id)
            ->assertJsonPath('links.next', null);
        $this->getJson('/api/v1/artists?type=person')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('meta.filter', 'person');
        $this->getJson('/api/v1/artists?sort=-name')
            ->assertOk()
            ->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('meta.sort', '-name');
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

    private function createLibrary(): PlexLibrary
    {
        $server = PlexServer::query()->create([
            'name' => 'Fixture Plex',
            'machine_identifier' => 'fixture-machine',
            'machine_identifier_hash' => hash('sha256', 'fixture-machine'),
            'version' => '1.0.0',
            'last_seen_at' => now(),
        ]);

        return PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '1',
            'section_uuid' => 'fixture-library',
            'title' => 'Music',
            'library_type' => 'artist',
            'last_synced_at' => now(),
        ]);
    }

    private function createArtist(PlexLibrary $library, string $name, ?string $type): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'agent',
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        if ($type !== null) {
            EntityMetadata::query()->create([
                'entity_id' => $entity->id,
                'source_provider' => 'fixture',
                'primary_type' => $type,
                'genres' => [],
                'enriched_at' => now(),
            ]);
        }
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => str($name)->slug()->toString(),
            'item_type' => 'artist',
            'title' => $name,
            'sort_title' => $name,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $entity->id,
            'match_scope' => 'agent',
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);

        return $entity;
    }
}
