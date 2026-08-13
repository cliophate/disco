<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\MediumTrack;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\MusicBrainz\MusicBrainzTracklistProjector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LongMusicTitleTest extends TestCase
{
    public function test_plex_and_catalog_preserve_titles_longer_than_255_characters(): void
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

        $title = rtrim(str_repeat('Unreasonably long music title ', 20));
        $server = PlexServer::query()->create([
            'name' => 'Plex',
            'machine_identifier' => 'test-machine',
            'machine_identifier_hash' => hash('sha256', 'test-machine'),
            'version' => '1',
            'last_seen_at' => now(),
        ]);
        $library = PlexLibrary::query()->create([
            'plex_server_id' => $server->id,
            'section_key' => '7',
            'section_uuid' => 'fixture-library',
            'title' => 'Music',
            'library_type' => 'artist',
        ]);
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => '301',
            'item_type' => 'track',
            'title' => $title,
            'sort_title' => $title,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        $entity = CatalogEntity::query()->create([
            'kind' => 'recording',
            'status' => 'active',
            'canonical_name' => $title,
            'sort_name' => $title,
        ]);
        $releaseGroup = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => 'Fixture album',
            'sort_name' => 'Fixture album',
        ]);
        ReleaseGroup::query()->create([
            'entity_id' => $releaseGroup->id,
            'primary_type' => 'album',
        ]);
        $releaseEntity = CatalogEntity::query()->create([
            'kind' => 'release',
            'status' => 'active',
            'canonical_name' => 'Fixture edition',
            'sort_name' => 'Fixture edition',
        ]);
        $release = Release::query()->create([
            'entity_id' => $releaseEntity->id,
            'release_group_id' => $releaseGroup->id,
        ]);
        app(MusicBrainzTracklistProjector::class)->project($release, ['media' => [[
            'position' => 1,
            'title' => $title,
            'format' => 'Digital Media',
            'tracks' => [[
                'position' => 1,
                'number' => '1',
                'title' => $title,
                'length' => 60_000,
            ]],
        ]]]);

        $this->assertSame($title, $item->fresh()->title);
        $this->assertSame($title, $entity->fresh()->canonical_name);
        $this->assertSame($title, MediumTrack::query()->value('title'));
    }
}
