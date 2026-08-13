<?php

namespace App\Music\Discovery;

use App\Models\HomeEdition;
use App\Models\RecommendationEvidence;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\SourceProvider;
use App\Music\Editorial\EditorialDiscoveryService;
use Illuminate\Support\Facades\DB;

class HomeEditionComposer
{
    public function __construct(
        private readonly HomeDiscoveryService $discovery,
        private readonly HomeProjectionVersion $version,
        private readonly EditorialDiscoveryService $editorial,
    ) {}

    public function generate(string $userId, ?string $calendarDay = null): HomeEdition
    {
        $calendarDay ??= now()->toDateString();
        $lockKey = "disco:home:{$userId}";
        DB::select('SELECT pg_advisory_lock(hashtext(?))', [$lockKey]);
        try {
            return DB::transaction(function () use ($calendarDay, $userId): HomeEdition {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $version = $this->version->current($userId, $calendarDay);
                $existing = HomeEdition::query()
                    ->where('user_id', $userId)
                    ->where('version_hash', $version)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                $plan = $this->discovery->plan($userId, $calendarDay);
                $payload = $plan['payload'];
                $payload['editorial'] = $this->editorial->current();
                $configuration = $plan['configuration'];
                $algorithm = (string) data_get($payload, 'meta.algorithm');
                $providers = SourceProvider::query()
                    ->whereIn('slug', collect($plan['recommendations'])->pluck('reasons.*.source')->flatten()->unique())
                    ->pluck('id', 'slug');
                $run = RecommendationRun::query()->create([
                    'user_id' => $userId,
                    'intent' => 'home_edition',
                    'input' => [
                        'configuration' => $configuration,
                        'facts_as_of' => data_get($payload, 'meta.facts_as_of'),
                        'source_coverage' => data_get($payload, 'meta.source_coverage'),
                    ],
                    'algorithm_version' => $algorithm,
                    'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
                    'random_seed' => (int) sprintf('%u', crc32($version)),
                    'catalog_version' => $version,
                    'status' => 'completed',
                    'generated_at' => now(),
                    'expires_at' => now()->addDays(180),
                ]);

                foreach ($plan['recommendations'] as $recommendation) {
                    $item = RecommendationItem::query()->create([
                        'run_id' => $run->id,
                        'entity_id' => $recommendation['entity_id'],
                        'rank' => $recommendation['rank'],
                        'score' => $recommendation['score'],
                        'component_scores' => $recommendation['component_scores'],
                        'eligibility' => $recommendation['eligibility'],
                        'module_type' => $recommendation['module_type'],
                        'explanation_text' => $recommendation['explanation_text'],
                        'explanation_version' => 'reasons-v1',
                    ]);
                    foreach ($recommendation['reasons'] as $reason) {
                        RecommendationEvidence::query()->create([
                            'recommendation_item_id' => $item->id,
                            'evidence_type' => $reason['code'],
                            'subject_entity_id' => $recommendation['entity_id'],
                            'predicate' => "discovery.reason.{$reason['code']}",
                            'object_entity_id' => $reason['object_entity_id'] ?? null,
                            'source_provider_id' => $providers[$reason['source']] ?? null,
                            'source_slug' => $reason['source'],
                            'weight' => 1,
                            'display_text' => $reason['text'],
                        ]);
                    }
                }

                return HomeEdition::query()->create([
                    'user_id' => $userId,
                    'recommendation_run_id' => $run->id,
                    'version_hash' => $version,
                    'algorithm_version' => $algorithm,
                    'facts_as_of' => (string) data_get($payload, 'meta.facts_as_of'),
                    'payload' => $payload,
                    'generated_at' => now(),
                ]);
            }, 3);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
        }
    }
}
