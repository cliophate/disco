<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\ExternalIdentifier;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Discovery\DiscoveryArtworkRefresher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\DiscoveryTestCase;

class DiscoveryMaintenanceTest extends DiscoveryTestCase
{
    public function test_newly_released_albums_retry_a_pre_release_missing_cover_before_the_normal_ttl(): void
    {
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Fixture', 'sort_name' => 'Fixture']);
        ReleaseGroup::query()->create(['entity_id' => $group->id]);
        $edition = CatalogEntity::query()->create(['kind' => 'release', 'status' => 'active', 'canonical_name' => 'Fixture', 'sort_name' => 'Fixture']);
        Release::query()->create(['entity_id' => $edition->id, 'release_group_id' => $group->id]);
        ExternalIdentifier::query()->create(['entity_id' => $edition->id, 'namespace' => 'musicbrainz.release', 'value' => $this->mbid(30), 'status' => 'active']);
        $this->pick(extra: ['entity_id' => $group->id, 'release_date' => now()->toDateString()]);
        CatalogEntityArtwork::query()->create(['entity_id' => $group->id, 'status' => 'missing', 'last_attempt_at' => now()->subDays(2)]);
        $ingestor = Mockery::mock(CoverArtArchiveIngestor::class);
        $ingestor->shouldReceive('ingest')->once()->with(Mockery::on(fn ($entity) => $entity->id === $group->id), $this->mbid(30), true)
            ->andReturn(new CatalogEntityArtwork(['status' => 'ready']));
        $this->assertSame(1, (new DiscoveryArtworkRefresher($ingestor))->refresh());
    }

    public function test_schedule_keeps_only_provider_artwork_and_discovery_jobs_in_utc(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($event) => preg_replace('/^.*artisan[\'\"]? /', '', $event->command));
        $this->assertCount(10, $events);
        $this->assertSame('0 * * * *', $events['disco:plex-sync']->expression);
        $this->assertSame('*/15 * * * *', $events['disco:listenbrainz-sync']->expression);
        $this->assertSame('0 5 * * 1', $events['disco:discovery-new-artists']->expression);
        $this->assertSame('0 7 * * *', $events['disco:discovery-new-releases']->expression);
        $this->assertSame('0 8 * * 1', $events['disco:discovery-more']->expression);
        foreach (['new-artists', 'new-releases', 'more'] as $section) {
            $this->assertSame(60, $events['disco:discovery-'.$section]->expiresAt);
        }
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }

    public function test_teardown_preserves_catalog_and_imports_and_drops_retired_tables(): void
    {
        foreach (['discovery.recommendation_runs', 'discovery.home_editions', 'discovery.upcoming_release_items', 'discovery.artist_discography_items', 'discovery.album_list_items', 'discovery.artist_follows', 'catalog.entity_narratives', 'catalog.credit_edges', 'library.plex_media_parts', 'app.manual_operations'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        foreach (['catalog.entities', 'catalog.release_groups', 'library.holdings', 'activity.listening_events', 'activity.listen_identities', 'discovery.batches', 'discovery.picks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }
}
