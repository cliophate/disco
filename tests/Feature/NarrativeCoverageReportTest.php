<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\ArtistDiscographyGeneration;
use App\Models\ArtistDiscographyItem;
use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\EntityNarrative;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexItemArtwork;
use App\Models\PlexItemGuid;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Artwork\PlexArtworkIngestor;
use App\Music\Descriptions\AlbumNarrativeEnricher;
use App\Music\Descriptions\NarrativeCoverageReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NarrativeCoverageReportTest extends TestCase
{
    public function test_report_separates_eligible_kinds_and_breaks_down_multiple_provider_records(): void
    {
        $this->preparePostgres();
        $library = $this->createLibrary();
        $album = $this->createAlbum($library, 'Covered Album', '11111111-1111-4111-8111-111111111111');
        $this->createAlbum($library, 'Album Without Identifier');
        $artist = $this->createArtist($library, 'Covered Artist', '22222222-2222-4222-8222-222222222222');
        $this->createNarrative($album, 'wikipedia', 'en', 'ready');
        $this->createNarrative($album, 'theaudiodb', 'de', 'missing');
        $this->createNarrative($album, 'narrative_pipeline', 'en', 'failed');
        $this->createNarrative($artist, 'wikipedia', 'fr', 'ready', now()->subDays(8));

        $report = app(NarrativeCoverageReport::class)->generate();

        $this->assertSame([
            'entity_kind' => 'album',
            'eligible' => 1,
            'ready' => 1,
            'missing' => 1,
            'stale' => 0,
            'failed' => 1,
            'unattempted' => 0,
        ], $report['coverage'][0]);
        $this->assertSame([
            'entity_kind' => 'artist',
            'eligible' => 1,
            'ready' => 0,
            'missing' => 0,
            'stale' => 1,
            'failed' => 0,
            'unattempted' => 0,
        ], $report['coverage'][1]);
        $this->assertContains([
            'entity_kind' => 'album',
            'provider' => 'theaudiodb',
            'language' => 'de',
            'status' => 'missing',
            'records' => 1,
        ], $report['breakdowns']);
        $this->assertContains([
            'entity_kind' => 'album',
            'provider' => 'wikipedia',
            'language' => 'en',
            'status' => 'ready',
            'records' => 1,
        ], $report['breakdowns']);
        $this->assertContains([
            'entity_kind' => 'artist',
            'provider' => 'wikipedia',
            'language' => 'fr',
            'status' => 'stale',
            'records' => 1,
        ], $report['breakdowns']);
        $this->artisan('disco:narrative-coverage', ['--json' => true])->assertSuccessful();
    }

    public function test_diagnostics_reconcile_paginate_authorize_and_retry_safe_failures(): void
    {
        $this->preparePostgres();
        $library = $this->createLibrary();
        $album = $this->createAlbum($library, 'Retry Album', '33333333-3333-4333-8333-333333333333');
        $this->createAlbum($library, 'Unidentified Album');
        $artist = $this->createArtist($library, 'Queued Artist', '22222222-2222-4222-8222-222222222222');
        $item = PlexItem::query()->where('title', 'Retry Album')->firstOrFail();
        $item->update(['thumb_key' => '/library/metadata/retry/thumb/1']);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $album->id,
            'match_scope' => 'release',
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);
        $artwork = PlexItemArtwork::query()->create([
            'plex_item_id' => $item->id,
            'status' => 'failed',
            'attempt_count' => 1,
            'last_error_code' => 'ProviderUnavailable',
            'last_attempt_at' => now()->subMinutes(6),
        ]);
        $this->createNarrative($album, 'theaudiodb', 'en', 'missing', now()->subDays(8));
        $generation = ArtistDiscographyGeneration::query()->create([
            'artist_entity_id' => $artist->id,
            'artist_mbid' => '22222222-2222-4222-8222-222222222222',
            'source_total' => 1,
            'page_count' => 1,
            'truncated' => false,
            'algorithm_version' => 'fixture-v1',
            'generated_at' => now()->subDays(91),
            'expires_at' => now()->subDay(),
        ]);
        ArtistDiscographyItem::query()->create([
            'generation_id' => $generation->id,
            'release_group_id' => $album->id,
            'release_group_mbid' => '33333333-3333-4333-8333-333333333333',
            'title' => 'Retry Album',
            'artist_credit' => [['name' => 'Queued Artist']],
            'primary_type' => 'Album',
            'secondary_types' => [],
            'date_precision' => 'unknown',
            'official_release_mbid' => '44444444-4444-4444-8444-444444444444',
            'position' => 1,
        ]);
        $catalogArtwork = CatalogEntityArtwork::query()->create([
            'entity_id' => $album->id,
            'status' => 'failed',
            'attempt_count' => 1,
            'last_error_code' => 'ProviderUnavailable',
            'last_attempt_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/metadata/diagnostics?type=album&category=identity&status=missing')->assertUnauthorized();
        $this->getJson('/api/v1/metadata/coverage')->assertUnauthorized();
        $this->getJson('/api/v1/metadata/pipelines/discography-artwork/diagnostics?status=failed')->assertUnauthorized();
        $this->get('/api/v1/metadata/pipelines/discography-artwork/diagnostics?status=failed')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]);
        $this->actingAs($user);

        $coverage = $this->getJson('/api/v1/metadata/coverage')->assertOk();
        $coverage->assertJsonPath('data.pipelines.2.name', 'Catalog enrichment')
            ->assertJsonPath('data.pipelines.2.status', 'building')
            ->assertJsonPath('data.pipelines.2.metrics.2.label', 'Queued')
            ->assertJsonPath('data.pipelines.2.metrics.2.value', 1)
            ->assertJsonPath('data.pipelines.3.status', 'disabled')
            ->assertJsonPath('data.pipelines.4.status', 'building')
            ->assertJsonPath('data.pipelines.4.metrics.1.label', 'Stale')
            ->assertJsonPath('data.pipelines.4.metrics.1.value', 1)
            ->assertJsonPath('data.pipelines.5.status', 'attention')
            ->assertJsonPath('data.pipelines.5.metrics.2.label', 'Failed')
            ->assertJsonPath('data.pipelines.5.metrics.2.value', 1);
        $this->getJson('/api/v1/metadata/pipelines/discographies/diagnostics?status=stale')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $artist->id)
            ->assertJsonPath('data.0.failure_category', 'refresh_due');
        $this->getJson('/api/v1/metadata/pipelines/discography-artwork/diagnostics?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $album->id)
            ->assertJsonPath('data.0.failure_category', 'ProviderUnavailable')
            ->assertJsonPath('data.0.retry_supported', false);
        $narrativeCount = $coverage->json('data.entities.1.statuses.narrative.missing');
        $artworkCount = $coverage->json('data.entities.1.statuses.artwork.failed');
        $this->getJson('/api/v1/metadata/diagnostics?type=album&category=narrative&status=missing&size=1')
            ->assertOk()
            ->assertJsonPath('meta.total', $narrativeCount)
            ->assertJsonPath('data.0.failure_category', 'provider_content_missing')
            ->assertJsonPath('data.0.retry_supported', true);
        $this->getJson('/api/v1/metadata/diagnostics?type=album&category=artwork&status=failed&size=1')
            ->assertOk()
            ->assertJsonPath('meta.total', $artworkCount)
            ->assertJsonPath('data.0.failure_category', 'ProviderUnavailable')
            ->assertJsonPath('data.0.retry_supported', true);
        $this->getJson('/api/v1/metadata/diagnostics?type=album&category=identity&status=missing&size=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.repair_note', 'Manual identity overrides are not supported; the next exact catalog sync may resolve this item.');

        $albumEnricher = Mockery::mock(AlbumNarrativeEnricher::class);
        $albumEnricher->shouldReceive('retryEntity')->once()->withArgs(fn (CatalogEntity $entity): bool => $entity->is($album))->andReturn([
            'attempted' => true,
            'status' => 'missing',
        ]);
        $this->app->instance(AlbumNarrativeEnricher::class, $albumEnricher);
        $artworkIngestor = Mockery::mock(PlexArtworkIngestor::class);
        $artwork->forceFill(['status' => 'ready', 'last_attempt_at' => now(), 'last_error_code' => null]);
        $artworkIngestor->shouldReceive('ingest')->once()->withArgs(fn (PlexItem $subject): bool => $subject->is($item))->andReturn($artwork);
        $this->app->instance(PlexArtworkIngestor::class, $artworkIngestor);
        $catalogArtwork->update(['last_attempt_at' => now()->subHours(25)]);
        $coverArtIngestor = Mockery::mock(CoverArtArchiveIngestor::class);
        $coverArtIngestor->shouldReceive('ingest')->once()->withArgs(fn (CatalogEntity $subject, string $mbid): bool => $subject->is($album) && $mbid === '44444444-4444-4444-8444-444444444444')
            ->andReturn($catalogArtwork->forceFill(['status' => 'ready', 'last_error_code' => null]));
        $this->app->instance(CoverArtArchiveIngestor::class, $coverArtIngestor);

        $this->postJson("/api/v1/metadata/diagnostics/narrative/{$album->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.attempted', true);
        $this->postJson("/api/v1/metadata/diagnostics/artwork/{$item->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
        $this->postJson("/api/v1/metadata/pipelines/discography-artwork/diagnostics/{$album->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.attempted', true)
            ->assertJsonPath('data.status', 'ready');
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

    private function createAlbum(PlexLibrary $library, string $title, ?string $mbid = null): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => $title,
            'sort_name' => $title,
        ]);
        ReleaseGroup::query()->create([
            'entity_id' => $entity->id,
            'primary_type' => 'album',
            'secondary_types' => [],
            'date_precision' => 'unknown',
        ]);
        if ($mbid !== null) {
            ExternalIdentifier::query()->create([
                'entity_id' => $entity->id,
                'namespace' => 'musicbrainz.release_group',
                'value' => $mbid,
                'status' => 'active',
            ]);
        }
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => $mbid ?? 'without-identifier',
            'item_type' => 'album',
            'title' => $title,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        Holding::query()->create([
            'release_group_id' => $entity->id,
            'plex_album_item_id' => $item->id,
            'ownership_type' => 'digital',
            'is_primary_playback_copy' => true,
        ]);

        return $entity;
    }

    private function createArtist(PlexLibrary $library, string $name, string $mbid): CatalogEntity
    {
        $entity = CatalogEntity::query()->create([
            'kind' => 'agent',
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        Agent::query()->create(['entity_id' => $entity->id, 'agent_type' => 'person']);
        ExternalIdentifier::query()->create([
            'entity_id' => $entity->id,
            'namespace' => 'musicbrainz.artist',
            'value' => $mbid,
            'status' => 'active',
        ]);
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => $mbid,
            'item_type' => 'artist',
            'title' => $name,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        PlexItemGuid::query()->create([
            'plex_item_id' => $item->id,
            'namespace' => 'mbid',
            'value' => $mbid,
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

    private function createNarrative(CatalogEntity $entity, string $provider, string $language, string $status, mixed $fetchedAt = null): void
    {
        $ready = $status === 'ready';
        EntityNarrative::query()->create([
            'entity_id' => $entity->id,
            'provider_slug' => $provider,
            'kind' => 'description',
            'language' => $language,
            'status' => $status,
            'body' => $ready ? 'Fixture narrative.' : null,
            'source_url' => $ready ? 'https://example.test/narrative' : null,
            'content_sha256' => $ready ? hash('sha256', 'Fixture narrative.') : null,
            'license_name' => $ready ? 'Fixture license' : null,
            'license_url' => $ready ? 'https://example.test/license' : null,
            'fetched_at' => $fetchedAt ?? now(),
        ]);
    }
}
