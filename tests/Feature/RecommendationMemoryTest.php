<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationImpression;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\ReleaseGroup;
use App\Models\User;
use App\Music\Discovery\BeyondLibraryDiscoveryService;
use App\Music\Discovery\RecommendationImpressionRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RecommendationMemoryTest extends TestCase
{
    public function test_presentations_are_distinct_from_generation_and_pinned_runs_keep_feedback_precedence(): void
    {
        $this->preparePostgres();
        config()->set('discovery.presentation_cooldown_days', 30);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $run = RecommendationRun::query()->create([
            'user_id' => $user->id,
            'intent' => 'beyond_library',
            'input' => [],
            'algorithm_version' => 'fixture',
            'configuration_hash' => str_repeat('a', 64),
            'random_seed' => 42,
            'catalog_version' => str_repeat('b', 64),
            'status' => 'completed',
            'generated_at' => now(),
            'expires_at' => now()->addWeek(),
        ]);
        $items = collect(range(1, 5))->map(function (int $rank) use ($run): RecommendationItem {
            $entity = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => "Album {$rank}", 'sort_name' => "Album {$rank}"]);
            ReleaseGroup::query()->create(['entity_id' => $entity->id, 'primary_type' => 'album', 'secondary_types' => [], 'date_precision' => 'unknown']);
            EntityMetadata::query()->create(['entity_id' => $entity->id, 'source_provider' => 'musicbrainz', 'artist_credit' => [['name' => "Artist {$rank}"]], 'enriched_at' => now()]);

            return RecommendationItem::query()->create([
                'run_id' => $run->id,
                'entity_id' => $entity->id,
                'rank' => $rank,
                'score' => 1 - ($rank / 10),
                'component_scores' => [],
                'eligibility' => ['scope' => 'external'],
                'module_type' => 'beyond-library',
                'explanation_text' => 'Fixture recommendation.',
                'explanation_version' => 'fixture',
            ]);
        });
        $recorder = app(RecommendationImpressionRecorder::class);
        $recorder->recordEntities($user->id, $run->id, [$items[0]->entity_id, $items[1]->entity_id], 'home', 'edition-1');
        $recorder->recordEntities($user->id, $run->id, [$items[0]->entity_id, $items[1]->entity_id], 'home', 'edition-1');
        $recorder->recordItems($user->id, [$items[2]->id], 'beyond', 'run-1:page-1');
        $this->assertSame(5, RecommendationItem::query()->count());
        $this->assertSame(3, RecommendationImpression::query()->count());

        RecommendationFeedback::query()->create(['user_id' => $user->id, 'entity_id' => $items[0]->entity_id, 'recommendation_item_id' => $items[0]->id, 'action' => 'interested']);
        RecommendationFeedback::query()->create(['user_id' => $user->id, 'entity_id' => $items[4]->entity_id, 'recommendation_item_id' => $items[4]->id, 'action' => 'not_for_me']);

        $presented = collect(app(BeyondLibraryDiscoveryService::class)->forUser($user->id, 20)['recommendations'])->pluck('entity_id');
        $this->assertTrue($presented->contains($items[0]->entity_id));
        $this->assertTrue($presented->contains($items[1]->entity_id));
        $this->assertFalse($presented->contains($items[4]->entity_id));
        $this->assertEqualsCanonicalizing([$items[0]->entity_id, $items[1]->entity_id, $items[2]->entity_id, $items[3]->entity_id], $presented->all());
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
