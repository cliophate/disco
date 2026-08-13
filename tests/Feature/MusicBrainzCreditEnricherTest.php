<?php

namespace Tests\Feature;

use App\Http\Presenters\ArtistRelationshipPresenter;
use App\Http\Presenters\CreditPresenter;
use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\CreditEdge;
use App\Models\ExternalIdentifier;
use App\Models\Recording;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\MusicBrainz\MusicBrainzClient;
use App\Music\MusicBrainz\MusicBrainzCreditEnricher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MusicBrainzCreditEnricherTest extends TestCase
{
    public function test_it_projects_typed_album_track_and_work_credits_with_provenance_idempotently(): void
    {
        $this->preparePostgres();
        $recording = $this->entity('recording', 'Credited Track');
        Recording::query()->create(['entity_id' => $recording->id, 'duration_ms' => 180000]);
        $recordingIdentifier = $this->identifier($recording, 'musicbrainz.recording', '11111111-1111-4111-8111-111111111111');
        $group = $this->entity('release_group', 'Credited Album');
        ReleaseGroup::query()->create(['entity_id' => $group->id, 'primary_type' => 'album', 'secondary_types' => [], 'date_precision' => 'unknown']);
        $release = $this->entity('release', 'Credited Album');
        Release::query()->create(['entity_id' => $release->id, 'release_group_id' => $group->id, 'status' => 'official', 'date_precision' => 'unknown']);
        $releaseIdentifier = $this->identifier($release, 'musicbrainz.release', '22222222-2222-4222-8222-222222222222');
        $emptyIdentifier = $this->identifier($group, 'musicbrainz.release_group', '33333333-3333-4333-8333-333333333333');
        $canonicalWriter = $this->entity('agent', 'Canonical Writer');
        Agent::query()->create(['entity_id' => $canonicalWriter->id, 'agent_type' => 'person']);
        $redirectedWriter = $this->entity('agent', 'Old Writer Identity');
        $redirectedWriter->update(['status' => 'redirected', 'redirect_entity_id' => $canonicalWriter->id]);
        $this->identifier($redirectedWriter, 'musicbrainz.artist', '99999999-9999-4999-8999-999999999999', 'redirected');

        $client = Mockery::mock(MusicBrainzClient::class);
        $client->shouldReceive('entity')->once()->with('recording', $recordingIdentifier->value)->andReturn($this->recordingPayload());
        $client->shouldReceive('entity')->once()->with('work', '88888888-8888-4888-8888-888888888888')->andReturn($this->workPayload());
        $client->shouldReceive('entity')->once()->with('release', $releaseIdentifier->value)->andReturn($this->releasePayload());
        $client->shouldReceive('entity')->once()->with('release-group', $emptyIdentifier->value)->andReturn(['id' => $emptyIdentifier->value, 'title' => 'Credited Album']);
        $this->app->instance(MusicBrainzClient::class, $client);
        $enricher = app(MusicBrainzCreditEnricher::class);

        $this->assertSame(4, $enricher->enrich($recordingIdentifier));
        $this->assertSame(4, $enricher->enrich($recordingIdentifier));
        $workIdentifier = ExternalIdentifier::query()->where('namespace', 'musicbrainz.work')->firstOrFail();
        $this->assertSame(1, $enricher->enrich($workIdentifier));
        $this->assertSame(1, $enricher->enrich($releaseIdentifier));
        $this->assertSame(0, $enricher->enrich($emptyIdentifier));

        $this->assertSame(6, CreditEdge::query()->count());
        $this->assertSame(['performer', 'producer', 'engineer', 'work'], CreditEdge::query()->where('subject_entity_id', $recording->id)->orderBy('position')->pluck('role')->all());
        $this->assertDatabaseHas('catalog.credit_edges', [
            'subject_entity_id' => $recording->id,
            'role' => 'performer',
            'credited_name' => 'Performer Alias',
        ]);
        $this->assertDatabaseHas('catalog.credit_edges', [
            'subject_entity_id' => $workIdentifier->entity_id,
            'role' => 'songwriter',
            'target_entity_id' => $canonicalWriter->id,
        ]);
        $this->assertSame(2, CreditEdge::query()->where('role', 'producer')->count());
        $this->assertSame(3, CreditEdge::query()->distinct('source_snapshot_id')->count('source_snapshot_id'));

        $credits = app(CreditPresenter::class)->forSubjects([$recording->id, $release->id, $group->id]);
        $songwriting = collect($credits[$recording->id]['groups'])->firstWhere('role', 'songwriter');
        $this->assertSame('Canonical Writer', $songwriting['items'][0]['name']);
        $this->assertSame('Fixture Work', $songwriting['items'][0]['via_work']['name']);
        $this->assertSame('MusicBrainz', $songwriting['items'][0]['provenance']['provider']);
        $this->assertSame('unavailable', $credits[$group->id]['status']);

        $producerId = ExternalIdentifier::query()->where('namespace', 'musicbrainz.artist')->where('value', '55555555-5555-4555-8555-555555555555')->value('entity_id');
        $producerRelationships = app(ArtistRelationshipPresenter::class)->present($producerId);
        $this->assertSame('available', $producerRelationships['status']);
        $this->assertContains('Canonical Performer', collect($producerRelationships['people'])->pluck('name'));
        $this->assertContains('Fixture Work', collect($producerRelationships['works'])->pluck('name'));
        $writerRelationships = app(ArtistRelationshipPresenter::class)->present($canonicalWriter->id);
        $this->assertSame('Fixture Work', $writerRelationships['works'][0]['name']);
        $this->assertLessThanOrEqual(12, count($producerRelationships['people']));
    }

    private function recordingPayload(): array
    {
        return [
            'id' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Credited Track',
            'artist-credit' => [[
                'name' => 'Performer Alias',
                'artist' => ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Canonical Performer'],
            ]],
            'relations' => [
                ['type' => 'producer', 'type-id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'artist' => ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Producer Person'], 'attributes' => []],
                ['type' => 'mix', 'type-id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'artist' => ['id' => '66666666-6666-4666-8666-666666666666', 'name' => 'Mix Engineer'], 'attributes' => ['mix']],
                ['type' => 'performance', 'type-id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'work' => ['id' => '88888888-8888-4888-8888-888888888888', 'name' => 'Fixture Work'], 'attributes' => []],
            ],
        ];
    }

    private function workPayload(): array
    {
        return [
            'id' => '88888888-8888-4888-8888-888888888888',
            'title' => 'Fixture Work',
            'relations' => [[
                'type' => 'composer',
                'type-id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                'artist' => ['id' => '99999999-9999-4999-8999-999999999999', 'name' => 'Writer Alias'],
                'target-credit' => 'Canonical Writer',
                'attributes' => [],
            ]],
        ];
    }

    private function releasePayload(): array
    {
        return [
            'id' => '22222222-2222-4222-8222-222222222222',
            'title' => 'Credited Album',
            'relations' => [[
                'type' => 'producer',
                'artist' => ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Producer Person'],
                'attributes' => [],
            ]],
        ];
    }

    private function entity(string $kind, string $name): CatalogEntity
    {
        return CatalogEntity::query()->create(['kind' => $kind, 'status' => 'active', 'canonical_name' => $name, 'sort_name' => $name]);
    }

    private function identifier(CatalogEntity $entity, string $namespace, string $value, string $status = 'active'): ExternalIdentifier
    {
        return ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => $namespace, 'value' => $value, 'status' => $status]);
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
