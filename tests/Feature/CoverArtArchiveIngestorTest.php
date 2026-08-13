<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Artwork\RasterArtworkProcessor;
use App\Music\Artwork\ReleaseGroupArtworkCandidateResolver;
use App\Music\CoverArtArchive\CoverArtArchiveClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CoverArtArchiveIngestorTest extends TestCase
{
    public function test_exact_basis_hit_does_not_browse_alternate_releases(): void
    {
        $this->preparePostgres();
        Storage::fake('artwork');
        $groupMbid = '11111111-1111-4111-8111-111111111111';
        $basisMbid = '22222222-2222-4222-8222-222222222222';
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Album', 'sort_name' => 'Album']);
        ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'Album', 'secondary_types' => []]);
        ExternalIdentifier::query()->create(['entity_id' => $group->id, 'namespace' => 'musicbrainz.release_group', 'value' => $groupMbid, 'status' => 'active']);
        $releaseEntity = CatalogEntity::query()->create(['kind' => 'release', 'status' => 'active', 'canonical_name' => 'Album', 'sort_name' => 'Album']);
        Release::query()->create(['entity_id' => $releaseEntity->id, 'release_group_id' => $group->id]);
        ExternalIdentifier::query()->create(['entity_id' => $releaseEntity->id, 'namespace' => 'musicbrainz.release', 'value' => $basisMbid, 'status' => 'active']);
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldNotReceive('releaseGroupReleases');
        $coverArt = Mockery::mock(CoverArtArchiveClient::class);
        $coverArt->shouldReceive('front')->once()->with($basisMbid)->andReturn(['release_mbid' => $basisMbid, 'image_id' => '1']);
        $coverArt->shouldReceive('download')->once()->with($basisMbid, '1')->andReturn([
            'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
            'width' => 1,
            'height' => 1,
        ]);
        $ingestor = new CoverArtArchiveIngestor($coverArt, app(RasterArtworkProcessor::class), new ReleaseGroupArtworkCandidateResolver($musicBrainz));

        $this->assertSame('ready', $ingestor->ingest($group, $basisMbid)->status);
    }

    public function test_exact_basis_miss_falls_through_to_a_deterministic_official_alternate(): void
    {
        $this->preparePostgres();
        Storage::fake('artwork');
        config()->set('services.cover_art_archive.release_attempt_limit', 5);
        $groupMbid = '11111111-1111-4111-8111-111111111111';
        $basisMbid = '22222222-2222-4222-8222-222222222222';
        $alternateMbid = '33333333-3333-4333-8333-333333333333';
        $bootlegMbid = '44444444-4444-4444-8444-444444444444';
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Album', 'sort_name' => 'Album']);
        ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'Album', 'secondary_types' => []]);
        ExternalIdentifier::query()->create(['entity_id' => $group->id, 'namespace' => 'musicbrainz.release_group', 'value' => $groupMbid, 'status' => 'active']);
        $releaseEntity = CatalogEntity::query()->create(['kind' => 'release', 'status' => 'active', 'canonical_name' => 'Album', 'sort_name' => 'Album']);
        Release::query()->create(['entity_id' => $releaseEntity->id, 'release_group_id' => $group->id]);
        ExternalIdentifier::query()->create(['entity_id' => $releaseEntity->id, 'namespace' => 'musicbrainz.release', 'value' => $basisMbid, 'status' => 'active']);

        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('releaseGroupReleases')->once()->with($groupMbid)->andReturn([
            ['id' => $bootlegMbid, 'status' => 'Bootleg', 'date' => '1999-01-01', 'cover-art-archive' => ['front' => true]],
            ['id' => $alternateMbid, 'status' => 'Official', 'date' => '2021-01-01', 'cover-art-archive' => ['front' => true]],
            ['id' => $basisMbid, 'status' => 'Official', 'date' => '2020-01-01', 'cover-art-archive' => ['front' => false]],
        ]);
        $coverArt = Mockery::mock(CoverArtArchiveClient::class);
        $coverArt->shouldReceive('front')->once()->with($basisMbid)->andReturnNull();
        $coverArt->shouldReceive('front')->once()->with($alternateMbid)->andReturn(['release_mbid' => $alternateMbid, 'image_id' => '7']);
        $coverArt->shouldNotReceive('front')->with($bootlegMbid);
        $coverArt->shouldReceive('download')->once()->with($alternateMbid, '7')->andReturn([
            'body' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
            'width' => 1,
            'height' => 1,
        ]);
        $ingestor = new CoverArtArchiveIngestor($coverArt, app(RasterArtworkProcessor::class), new ReleaseGroupArtworkCandidateResolver($musicBrainz));

        $artwork = $ingestor->ingest($group, $basisMbid);

        $this->assertSame('ready', $artwork->status);
        $this->assertSame($alternateMbid, $artwork->source_release_mbid);
        $this->assertSame('7', $artwork->source_image_id);
        Storage::disk('artwork')->assertExists($artwork->storage_key);
        $this->assertSame(1, $artwork->attempt_count);
        $this->assertSame($artwork->id, $ingestor->ingest($group, $basisMbid)->id);
        $this->assertSame(1, $artwork->refresh()->attempt_count);

        ExternalIdentifier::query()->where('namespace', 'musicbrainz.release')->where('value', $basisMbid)->update(['status' => 'retired']);
        $this->expectException(RuntimeException::class);
        $ingestor->ingest($group, $basisMbid);
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
