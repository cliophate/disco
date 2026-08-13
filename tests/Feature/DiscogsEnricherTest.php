<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\DiscogsEnrichmentState;
use App\Models\EntityResolution;
use App\Models\ExternalIdentifier;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexServer;
use App\Models\ReleaseGroup;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Music\Discogs\DiscogsClient;
use App\Music\Discogs\DiscogsEnricher;
use App\Music\Discogs\DiscogsMetadataPresenter;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DiscogsEnricherTest extends TestCase
{
    public function test_exact_musicbrainz_artist_link_is_sanitized_attributed_fresh_and_idempotent(): void
    {
        $this->preparePostgres();
        $artist = $this->createOwnedEntity('agent', 'Fixture Artist', '11111111-1111-4111-8111-111111111111');
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->once()->with('artist', '11111111-1111-4111-8111-111111111111')->andReturn([
            'relations' => [['target-type' => 'url', 'url' => ['resource' => 'https://www.discogs.com/artist/42-Fixture-Artist']]],
        ]);
        $discogs = Mockery::mock(DiscogsClient::class);
        $discogs->shouldReceive('configured')->twice()->andReturnTrue();
        $discogs->shouldReceive('catalogObject')->twice()->with('artist', '42')->andReturn([
            'id' => 42,
            'name' => 'Fixture Artist',
            'realname' => 'Fixture Person',
            'namevariations' => ['F. Artist'],
            'profile' => 'Restricted profile prose.',
            'images' => [['uri' => 'https://example.test/restricted.jpg']],
            'resource_url' => 'https://api.discogs.com/artists/42',
        ]);
        $enricher = new DiscogsEnricher($discogs, $musicBrainz);

        $first = $enricher->enrichOwned(1);
        $this->assertSame(1, $first['matched']);
        $this->assertSame(3, $first['restricted_fields_dropped']);
        $this->assertDatabaseHas('catalog.external_identifiers', ['entity_id' => $artist->id, 'namespace' => 'discogs.artist', 'value' => '42', 'status' => 'active']);
        $resolution = EntityResolution::query()->where('entity_id', $artist->id)->where('method', 'musicbrainz_url')->sole();
        $this->assertSame('1.0000', $resolution->confidence);
        $this->assertSame('https://www.discogs.com/artist/42', $resolution->evidence['relation_url']);
        $snapshot = SourceSnapshot::query()->sole();
        $this->assertSame('Fixture Person', $snapshot->payload['real_name']);
        $this->assertArrayNotHasKey('profile', $snapshot->payload);
        $this->assertArrayNotHasKey('images', $snapshot->payload);
        $this->assertArrayNotHasKey('resource_url', $snapshot->payload);
        $presented = app(DiscogsMetadataPresenter::class)->forEntity($artist->id);
        $this->assertSame('https://www.discogs.com/artist/42', $presented['source_url']);
        $this->assertSame('Fixture Person', $presented['fields']['real_name']);

        $second = $enricher->enrichOwned(1, force: true);
        $this->assertSame(1, $second['refreshed']);
        $this->assertSame(1, SourceSnapshot::query()->count());
        $this->assertSame(0, $enricher->coverage()['restricted_snapshots']);

        SourceSnapshot::query()->update(['expires_at' => now()->subSecond()]);
        $this->assertNull(app(DiscogsMetadataPresenter::class)->forEntity($artist->id));
    }

    public function test_multiple_musicbrainz_links_are_ambiguous_and_never_contact_discogs(): void
    {
        $this->preparePostgres();
        $artist = $this->createOwnedEntity('agent', 'Ambiguous Artist', '22222222-2222-4222-8222-222222222222');
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->once()->andReturn(['relations' => [
            ['url' => ['resource' => 'https://www.discogs.com/artist/10-One']],
            ['url' => ['resource' => 'https://www.discogs.com/artist/20-Two']],
        ]]);
        $discogs = Mockery::mock(DiscogsClient::class);
        $discogs->shouldReceive('configured')->once()->andReturnTrue();
        $discogs->shouldNotReceive('catalogObject');

        $counts = (new DiscogsEnricher($discogs, $musicBrainz))->enrichOwned(10);

        $this->assertSame(1, $counts['ambiguous']);
        $this->assertSame(0, EntityResolution::query()->count());
        $state = DiscogsEnrichmentState::query()->findOrFail($artist->id);
        $this->assertSame('ambiguous', $state->status);
        $this->assertCount(2, $state->evidence['candidate_urls']);
    }

    public function test_exact_album_release_link_promotes_only_approved_catalog_fields(): void
    {
        $this->preparePostgres();
        $album = $this->createOwnedEntity('release_group', 'Fixture Album', '33333333-3333-4333-8333-333333333333');
        $musicBrainz = Mockery::mock(MusicBrainzClient::class);
        $musicBrainz->shouldReceive('entity')->once()->with('release-group', '33333333-3333-4333-8333-333333333333')->andReturn([
            'relations' => [['url' => ['resource' => 'https://www.discogs.com/release/99-Fixture-Album']]],
        ]);
        $discogs = Mockery::mock(DiscogsClient::class);
        $discogs->shouldReceive('configured')->once()->andReturnTrue();
        $discogs->shouldReceive('catalogObject')->once()->with('release', '99')->andReturn([
            'id' => 99,
            'title' => 'Fixture Album',
            'country' => 'UK',
            'genres' => ['Rock'],
            'styles' => ['Post-Punk'],
            'formats' => [['name' => 'Vinyl', 'qty' => '1', 'descriptions' => ['LP']]],
            'labels' => [['id' => 7, 'name' => 'Fixture Label', 'catno' => 'CAT-99']],
            'identifiers' => [['type' => 'Barcode', 'value' => '123456789']],
            'community' => ['have' => 100],
            'lowest_price' => 10.5,
            'images' => [['uri' => 'https://example.test/restricted.jpg']],
        ]);

        $counts = (new DiscogsEnricher($discogs, $musicBrainz))->enrichOwned(1);

        $this->assertSame(1, $counts['matched']);
        $payload = SourceSnapshot::query()->sole()->payload;
        $this->assertSame('Post-Punk', $payload['styles'][0]);
        $this->assertSame('CAT-99', $payload['labels'][0]['catalog_number']);
        $this->assertSame('123456789', $payload['identifiers'][0]['value']);
        $this->assertArrayNotHasKey('community', $payload);
        $this->assertArrayNotHasKey('lowest_price', $payload);
        $this->assertArrayNotHasKey('images', $payload);
        $this->assertDatabaseHas('catalog.external_identifiers', [
            'entity_id' => $album->id,
            'namespace' => 'discogs.release',
            'value' => '99',
            'status' => 'active',
        ]);
    }

    public function test_discogs_job_is_bounded_scheduled_and_supports_non_writing_dry_runs(): void
    {
        $enricher = Mockery::mock(DiscogsEnricher::class);
        $coverage = ['eligible' => 2, 'identified' => 1, 'fresh' => 1, 'stale' => 0, 'restricted_snapshots' => 0];
        $counts = ['enabled' => true, 'requested' => 1, 'matched' => 1, 'refreshed' => 0, 'missing' => 0, 'ambiguous' => 0, 'conflicts' => 0, 'failed' => 0, 'musicbrainz_requests' => 1, 'discogs_requests' => 1, 'restricted_fields_dropped' => 2];
        $enricher->shouldReceive('coverage')->twice()->andReturn($coverage);
        $enricher->shouldReceive('enrichOwned')->once()->with(1, false, true)->andReturn($counts);
        $this->app->instance(DiscogsEnricher::class, $enricher);

        $this->artisan('disco:discogs-enrich', ['--limit' => 1, '--dry-run' => true])->assertSuccessful();
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'disco:discogs-enrich --limit=20'));
        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_pipeline_diagnostics_authorize_reconcile_and_retry_due_failures(): void
    {
        $this->preparePostgres();
        $artist = $this->createOwnedEntity('agent', 'Failed Artist', '55555555-5555-4555-8555-555555555555');
        DiscogsEnrichmentState::query()->create([
            'entity_id' => $artist->id,
            'status' => 'failed',
            'attempted_at' => now()->subHours(7),
            'retry_at' => now()->subHour(),
            'error_code' => 'ProviderUnavailable',
            'evidence' => [],
        ]);
        $enricher = Mockery::mock(DiscogsEnricher::class);
        $enricher->shouldReceive('configured')->andReturnTrue();
        $enricher->shouldReceive('eligibleEntityIds')->andReturn(collect([$artist->id]));
        $enricher->shouldReceive('retryEntity')->once()->withArgs(fn (CatalogEntity $entity): bool => $entity->is($artist))->andReturn([
            'status' => 'matched',
            'musicbrainz_requests' => 1,
            'discogs_requests' => 1,
            'restricted_fields_dropped' => 0,
        ]);
        $this->app->instance(DiscogsEnricher::class, $enricher);

        $url = '/api/v1/metadata/pipelines/discogs/diagnostics?status=failed';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('not-a-real-password'),
        ]));

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $artist->id)
            ->assertJsonPath('data.0.failure_category', 'ProviderUnavailable')
            ->assertJsonPath('data.0.retry_supported', true);
        $this->postJson("/api/v1/metadata/pipelines/discogs/diagnostics/{$artist->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.attempted', true)
            ->assertJsonPath('data.status', 'matched');
    }

    private function preparePostgres(): void
    {
        config()->set('services.discogs.token', 'fixture-token');
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

    private function createOwnedEntity(string $kind, string $name, string $mbid): CatalogEntity
    {
        $server = PlexServer::query()->firstOrCreate(
            ['machine_identifier' => 'fixture-machine'],
            ['name' => 'Fixture Plex', 'machine_identifier_hash' => hash('sha256', 'fixture-machine'), 'version' => '1.0', 'last_seen_at' => now()],
        );
        $library = PlexLibrary::query()->firstOrCreate(
            ['plex_server_id' => $server->id, 'section_key' => '1'],
            ['section_uuid' => 'fixture-library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()],
        );
        $entity = CatalogEntity::query()->create(['kind' => $kind, 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
        $namespace = $kind === 'agent' ? 'musicbrainz.artist' : 'musicbrainz.release_group';
        $scope = $kind === 'agent' ? 'agent' : 'release_group';
        $itemType = $kind === 'agent' ? 'artist' : 'album';
        if ($kind === 'agent') {
            Agent::query()->create(['entity_id' => $entity->id, 'agent_type' => 'person']);
        } else {
            ReleaseGroup::query()->create(['entity_id' => $entity->id, 'primary_type' => 'album', 'secondary_types' => [], 'date_precision' => 'unknown']);
        }
        ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => $namespace, 'value' => $mbid, 'status' => 'active']);
        $item = PlexItem::query()->create([
            'plex_library_id' => $library->id,
            'rating_key' => (string) (PlexItem::query()->count() + 1),
            'item_type' => $itemType,
            'title' => $name,
            'raw_metadata' => [],
            'last_synced_at' => now(),
        ]);
        PlexEntityMatch::query()->create([
            'plex_item_id' => $item->id,
            'entity_id' => $entity->id,
            'match_scope' => $scope,
            'status' => 'confirmed',
            'method' => 'external_id',
            'confidence' => 1,
        ]);

        return $entity;
    }
}
