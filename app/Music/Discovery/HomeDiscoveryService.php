<?php

namespace App\Music\Discovery;

use App\Http\Presenters\AlbumPresenter;
use App\Models\AlbumListItem;
use App\Models\CatalogEntityArtwork;
use App\Models\EntityMetadata;
use App\Models\ListenImportRun;
use App\Models\PlayAggregate;
use App\Models\PlexItem;
use App\Models\PlexItemArtwork;
use App\Models\PlexSyncRun;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationImpression;
use App\Models\SourceAccount;
use App\Music\Library\AlbumFactsService;
use App\Music\Personal\AlbumListService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HomeDiscoveryService
{
    public const ALGORITHM = 'daily-feature-lenses-v9';

    private const REDISCOVER_DAYS = 180;

    private const RECENTLY_HEARD_DAYS = 90;

    private const QUICK_LISTEN_MINUTES = 45;

    private const SECTION_ITEMS = 8;

    private const SECTION_MINIMUM = 3;

    private const RECENT_ARTISTS = 10;

    public function __construct(
        private readonly AlbumPresenter $presenter,
        private readonly AlbumFactsService $factsService,
        private readonly BeyondLibraryDiscoveryService $beyondLibrary,
        private readonly DailyFeatureSelector $featureSelector,
        private readonly RecommendationDiversifier $diversifier,
        private readonly AlbumListService $albumLists,
        private readonly ArtistPreferencePolicy $artistPreferences,
    ) {}

    /** @return array<string, mixed> */
    public function build(?string $userId = null, ?string $calendarDay = null): array
    {
        return $this->plan($userId, $calendarDay)['payload'];
    }

    /** @return array<string, mixed>|null */
    public function lens(string $type, ?string $userId = null, ?string $calendarDay = null): ?array
    {
        return $this->plan($userId, $calendarDay)['lenses'][$type] ?? null;
    }

    /** @return array{payload:array<string,mixed>,recommendations:list<array<string,mixed>>,configuration:array<string,mixed>,lenses:array<string,array<string,mixed>>} */
    public function plan(?string $userId = null, ?string $calendarDay = null): array
    {
        $calendarDay ??= now()->toDateString();
        $latestSync = PlexSyncRun::query()->where('status', 'completed')->latest('completed_at')->first();
        $listenBrainzAccount = SourceAccount::query()
            ->when($userId !== null, fn ($query) => $query->where('owner_user_id', $userId))
            ->when($userId === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('slug', 'listenbrainz')->where('enabled', true))
            ->first();
        $latestListenImport = ListenImportRun::query()
            ->when($listenBrainzAccount !== null, fn ($query) => $query->where('source_account_id', $listenBrainzAccount->id))
            ->when($listenBrainzAccount === null, fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', 'completed')->latest('completed_at')->first();
        $latestFactsAt = collect([
            $latestSync?->completed_at,
            PlayAggregate::query()->max('updated_at'),
            EntityMetadata::query()->max('updated_at'),
            PlexItemArtwork::query()->max('updated_at'),
            CatalogEntityArtwork::query()->max('updated_at'),
        ])->filter()->map(fn ($value) => CarbonImmutable::parse($value))->sort()->last();
        $now = $latestFactsAt === null
            ? CarbonImmutable::now()->startOfHour()
            : CarbonImmutable::parse($latestFactsAt);
        $albums = PlexItem::query()
            ->where('item_type', 'album')
            ->whereNull('removed_at')
            ->whereHas('matches', fn ($query) => $query
                ->where('match_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate']))
            ->with(['artwork', 'guids', 'matches.entity.metadata', 'matches.entity.release'])
            ->get();
        $artists = PlexItem::query()
            ->where('item_type', 'artist')
            ->whereNull('removed_at')
            ->whereIn('rating_key', $albums->pluck('parent_rating_key')->filter()->unique())
            ->with(['artwork', 'matches.entity.metadata'])
            ->get()
            ->keyBy(fn (PlexItem $artist): string => "{$artist->plex_library_id}:{$artist->rating_key}");
        $facts = $this->factsService->forAlbums($albums);

        $candidates = $albums->map(function (PlexItem $album) use ($artists, $facts, $now): array {
            $artist = $artists->get("{$album->plex_library_id}:{$album->parent_rating_key}");
            $albumFacts = $facts["{$album->plex_library_id}:{$album->rating_key}"] ?? [];
            $lastHeard = isset($albumFacts['last_heard_at']) && $albumFacts['last_heard_at'] !== null
                ? CarbonImmutable::parse($albumFacts['last_heard_at'])
                : null;
            $addedAt = $album->added_at_plex === null ? null : CarbonImmutable::parse($album->added_at_plex);
            $summary = $this->presenter->summary($album, $artist, $albumFacts);

            return [
                'id' => $summary['id'],
                'album' => $summary,
                'artist_key' => $artist === null ? null : "{$artist->plex_library_id}:{$artist->rating_key}",
                'artist_preference_eligible' => is_string(data_get($summary, 'artist.id'))
                    && $this->artistPreferences->allowsId(data_get($summary, 'artist.id')),
                'added_at' => $addedAt,
                'days_since_added' => $addedAt === null ? null : max(0, (int) $addedAt->diffInDays($now)),
                'last_heard_at' => $lastHeard,
                'last_heard_source' => $albumFacts['last_heard_source'] ?? null,
                'days_since_heard' => $lastHeard === null ? null : max(0, (int) $lastHeard->diffInDays($now)),
                'play_count' => (int) ($albumFacts['play_count'] ?? 0),
                'has_play_signal' => (bool) ($albumFacts['has_play_signal'] ?? false),
                'duration_ms' => (int) ($albumFacts['duration_ms'] ?? 0),
                'listenbrainz_count' => (int) data_get($albumFacts, 'listenbrainz.listen_count', 0),
            ];
        })->unique('id')->values();
        $allCandidates = $candidates;
        if ($userId !== null) {
            $this->albumLists->normalize($userId);
            $excluded = RecommendationFeedback::query()
                ->where('user_id', $userId)
                ->whereIn('action', ['not_for_me', 'already_know', 'wrong_match'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('entity_id');
            $listened = AlbumListItem::query()->where('user_id', $userId)->where('status', 'listened')->pluck('release_group_entity_id');
            $excluded = $excluded->merge($listened)->unique();
            $candidates = $candidates->reject(fn (array $item): bool => $excluded->contains($item['id']))->values();
            $interested = RecommendationFeedback::query()
                ->where('user_id', $userId)
                ->where('action', 'interested')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('entity_id');
            $recentlyPresented = RecommendationImpression::query()
                ->where('user_id', $userId)
                ->where('presented_at', '>=', now()->subDays((int) config('discovery.presentation_cooldown_days', 30)))
                ->whereNotIn('entity_id', $interested)
                ->pluck('entity_id')
                ->flip();
            $candidates = $candidates->map(fn (array $item): array => [
                ...$item,
                'recently_presented' => $recentlyPresented->has($item['id']),
            ]);
        }

        $waiting = $candidates
            ->filter(fn (array $item): bool => ! $item['has_play_signal'])
            ->map(fn (array $item): array => $this->recommendation(
                $item,
                min(1, (($item['days_since_added'] ?? 0) / 730)),
                array_values(array_filter([
                    [
                        'code' => 'no_listen_signal',
                        'text' => $latestListenImport === null
                            ? 'Plex records no plays for this album.'
                            : 'No matched ListenBrainz listens or Plex play signals.',
                        'source' => $latestListenImport === null ? 'plex' : 'listenbrainz',
                    ],
                    $item['days_since_added'] === null ? null : [
                        'code' => 'time_on_shelf',
                        'text' => "Added {$item['days_since_added']} days ago.",
                        'source' => 'plex',
                    ],
                ])),
            ));

        $rediscover = $candidates
            ->filter(fn (array $item): bool => ($item['days_since_heard'] ?? 0) >= self::REDISCOVER_DAYS)
            ->map(fn (array $item): array => $this->recommendation(
                $item,
                min(1, (($item['days_since_heard'] - self::REDISCOVER_DAYS) / 550) * 0.8 + min(1, log(1 + $item['play_count']) / log(11)) * 0.2),
                [[
                    'code' => 'last_heard',
                    'text' => 'Last '.($item['last_heard_source'] === 'listenbrainz' ? 'ListenBrainz listen' : 'Plex play signal')." was {$item['days_since_heard']} days ago.",
                    'source' => $item['last_heard_source'] ?? 'plex',
                ]],
            ));

        $recentlyHeard = $candidates
            ->filter(fn (array $item): bool => $item['last_heard_at'] !== null && ($item['days_since_heard'] ?? 9999) <= self::RECENTLY_HEARD_DAYS)
            ->map(fn (array $item): array => $this->recommendation(
                $item,
                exp(-($item['days_since_heard'] ?? 0) / 30),
                [[
                    'code' => 'recently_heard',
                    'text' => ($item['last_heard_source'] === 'listenbrainz' ? 'Listened' : 'Plex recorded a play')." {$item['days_since_heard']} days ago.",
                    'source' => $item['last_heard_source'] ?? 'plex',
                ]],
            ));

        $quick = $candidates
            ->filter(fn (array $item): bool => $item['duration_ms'] > 0 && $item['duration_ms'] <= self::QUICK_LISTEN_MINUTES * 60 * 1000)
            ->map(function (array $item): array {
                $minutes = (int) round($item['duration_ms'] / 60_000);
                $gapNeed = $item['last_heard_at'] === null ? 1 : min(1, max(0, (($item['days_since_heard'] ?? 0) - self::RECENTLY_HEARD_DAYS) / 640));

                return $this->recommendation(
                    $item,
                    min(1, $gapNeed * 0.6 + min(1, max(0, (self::QUICK_LISTEN_MINUTES - $minutes) / 30)) * 0.4),
                    [[
                        'code' => 'short_runtime',
                        'text' => "Runs {$minutes} minutes.",
                        'source' => 'plex',
                    ]],
                );
            });

        $recent = $candidates
            ->filter(fn (array $item): bool => $item['added_at'] !== null)
            ->map(fn (array $item): array => $this->recommendation(
                $item,
                exp(-($item['days_since_added'] ?? 0) / 45),
                [[
                    'code' => 'recently_added',
                    'text' => "Added to Plex {$item['days_since_added']} days ago.",
                    'source' => 'plex',
                ]],
            ));

        $artistTrail = $this->artistTrail($candidates);
        $ranked = [
            ['type' => 'waiting', 'title' => 'Waiting on your shelves', 'description' => 'Owned albums with no matched listening signal from Plex or ListenBrainz.', 'items' => $waiting],
            ['type' => 'rediscover', 'title' => 'Rediscover', 'description' => 'Albums that have been absent from your recent listening.', 'items' => $rediscover],
            ['type' => 'recently-heard', 'title' => 'Recently heard', 'description' => 'Owned albums present in your recent listening history.', 'items' => $recentlyHeard],
            ['type' => 'artist-trail', 'title' => 'Continue with an artist', 'description' => 'Another owned album by an artist heard recently.', 'items' => $artistTrail],
            ['type' => 'quick-listen', 'title' => 'Under 45 minutes', 'description' => 'Shorter albums selected using real track runtimes.', 'items' => $quick],
            ['type' => 'recently-added', 'title' => 'Latest additions', 'description' => 'The newest albums added to this Plex library.', 'items' => $recent],
        ];
        $lenses = collect($ranked)->mapWithKeys(fn (array $section): array => [
            $section['type'] => [
                'type' => $section['type'],
                'title' => $section['title'],
                'description' => $section['description'],
                'items' => $this->diversifier->home($section['items'], $section['items']->count(), "{$calendarDay}:{$section['type']}:lens")
                    ->values()
                    ->map(fn (array $item): array => [...$this->publicRecommendation($item), 'lens' => $section['title']])
                    ->all(),
            ],
        ])->all();
        $componentScores = [];
        foreach ($ranked as $section) {
            foreach ($section['items'] as $item) {
                $componentScores[$item['album']['id']][$section['type']] = $item['rank_score'];
            }
        }

        $beyond = $userId === null ? null : $this->beyondLibrary->forUser($userId);
        $featureCandidates = collect($ranked)->flatMap(fn (array $section): Collection => $section['items']->map(
            fn (array $item): array => [...$item, 'lens' => $section['title'], 'module_type' => $section['type'], 'scope' => 'owned'],
        ));
        if ($beyond !== null) {
            $featureCandidates = $featureCandidates->merge(collect($beyond['recommendations'])->map(fn (array $recommendation): array => [
                'album' => $recommendation['album'],
                'rank_score' => $recommendation['score'],
                'reasons' => $recommendation['reasons'],
                'lens' => 'Beyond your library',
                'module_type' => 'beyond-library',
                'scope' => 'beyond',
            ]));
        }
        $feature = $this->featureSelector->select($featureCandidates, $calendarDay);
        $featureScope = $feature['scope'] ?? null;
        $selected = collect($featureScope === 'owned' ? [$feature] : []);
        $used = $feature === null ? [] : [$feature['album']['id'] => true];
        $artistCounts = [];
        $registerArtists = function (Collection $items) use (&$artistCounts): void {
            foreach ($items as $item) {
                $artist = (string) (data_get($item, 'album.artist.id') ?? data_get($item, 'album.artist.name', ''));
                if ($artist !== '') {
                    $artistCounts[$artist] = ($artistCounts[$artist] ?? 0) + 1;
                }
            }
        };
        $registerArtists(collect($feature === null ? [] : [$feature]));
        $sections = [];
        foreach ($ranked as $section) {
            $items = $this->diversifier->home(
                $section['items']->reject(fn (array $item): bool => isset($used[$item['album']['id']]))->values(),
                self::SECTION_ITEMS,
                "{$calendarDay}:{$section['type']}",
                $artistCounts,
            );
            if ($items->count() < self::SECTION_MINIMUM) {
                continue;
            }
            foreach ($items as $item) {
                $used[$item['album']['id']] = true;
                $selected->push([...$item, 'module_type' => $section['type']]);
            }
            $registerArtists($items);
            $sections[] = [
                'type' => $section['type'],
                'title' => $section['title'],
                'description' => $section['description'],
                'total' => count($lenses[$section['type']]['items']),
                'items' => $items->map(fn (array $item): array => $this->publicRecommendation($item)),
            ];
        }

        $recentArtists = $allCandidates
            ->where('artist_preference_eligible', true)
            ->sortByDesc(fn (array $item): int => $item['last_heard_at']?->getTimestamp() ?? $item['added_at']?->getTimestamp() ?? 0)
            ->pluck('album.artist')
            ->filter()
            ->unique('id')
            ->take(self::RECENT_ARTISTS)
            ->values();
        $candidateFacts = $candidates->keyBy('id');
        $ownedRecommendations = $selected->values()->map(function (array $recommendation) use ($candidateFacts, $componentScores): array {
            $entityId = $recommendation['album']['id'];
            $facts = $candidateFacts->get($entityId, []);

            return [
                'entity_id' => $entityId,
                'score' => $recommendation['rank_score'],
                'component_scores' => $componentScores[$entityId] ?? [],
                'eligibility' => [
                    'scope' => 'owned',
                    'has_play_signal' => (bool) ($facts['has_play_signal'] ?? false),
                    'play_count' => (int) ($facts['play_count'] ?? 0),
                    'days_since_added' => $facts['days_since_added'] ?? null,
                    'days_since_heard' => $facts['days_since_heard'] ?? null,
                    'duration_ms' => (int) ($facts['duration_ms'] ?? 0),
                    'recently_presented' => (bool) ($facts['recently_presented'] ?? false),
                ],
                'module_type' => $recommendation['module_type'],
                'reasons' => $recommendation['reasons'],
                'explanation_text' => collect($recommendation['reasons'])->pluck('text')->implode(' '),
            ];
        });
        $visibleBeyondIds = collect($featureScope === 'beyond' ? [data_get($feature, 'album.id')] : [])->filter();
        if ($beyond !== null) {
            $beyondSection = $beyond['section'];
            $beyondItems = collect($beyondSection['items'])
                ->reject(fn (array $item): bool => data_get($item, 'album.id') === data_get($feature, 'album.id'))
                ->values();
            $beyondItems = $this->diversifier->home($beyondItems, self::SECTION_ITEMS, "{$calendarDay}:beyond-library", $artistCounts);
            $beyondSection['items'] = $beyondItems->all();
            $visibleBeyondIds = $visibleBeyondIds->merge($beyondItems->pluck('album.id'))->filter()->unique()->values();
            if ($beyondItems->isNotEmpty()) {
                $sections[] = $beyondSection;
            }
        }
        $beyondRecommendations = collect($beyond['recommendations'] ?? [])
            ->whereIn('entity_id', $visibleBeyondIds)
            ->map(fn (array $recommendation): array => [
                ...$recommendation,
                'eligibility' => [...$recommendation['eligibility'], 'scope' => 'beyond'],
            ]);
        if ($featureScope === 'beyond') {
            $featureId = data_get($feature, 'album.id');
            $featureRecommendation = $beyondRecommendations->firstWhere('entity_id', $featureId);
            $orderedRecommendations = collect($featureRecommendation === null ? [] : [$featureRecommendation])
                ->merge($ownedRecommendations)
                ->merge($beyondRecommendations->reject(fn (array $recommendation): bool => $recommendation['entity_id'] === $featureId));
        } else {
            $orderedRecommendations = $ownedRecommendations->merge($beyondRecommendations);
        }
        $recommendations = $orderedRecommendations
            ->values()
            ->map(fn (array $recommendation, int $index): array => [...$recommendation, 'rank' => $index + 1])
            ->all();

        return [
            'payload' => [
                'feature' => $feature === null ? null : $this->publicRecommendation($feature),
                'sections' => $sections,
                'recent_artists' => $recentArtists,
                'collection' => [
                    'artists' => PlexItem::query()->where('item_type', 'artist')->whereNull('removed_at')->count(),
                    'albums' => $allCandidates->count(),
                    'tracks' => PlexItem::query()->where('item_type', 'track')->whereNull('removed_at')->count(),
                ],
                'meta' => [
                    'algorithm' => self::ALGORITHM,
                    'generated_at' => $now->toAtomString(),
                    'facts_as_of' => $now->toAtomString(),
                    'last_plex_sync_at' => $latestSync?->completed_at?->toAtomString(),
                    'last_listenbrainz_import_at' => $latestListenImport?->completed_at?->toAtomString(),
                    'source_coverage' => [
                        'plex' => $allCandidates->isEmpty() ? 0 : 1,
                        'listenbrainz' => $allCandidates->isEmpty() ? 0 : round(
                            $allCandidates->filter(fn (array $item): bool => $item['listenbrainz_count'] > 0)->count() / $allCandidates->count(),
                            4,
                        ),
                        'musicbrainz' => $allCandidates->isEmpty() ? 0 : round(
                            $allCandidates->filter(fn (array $item): bool => $item['album']['metadata_status'] === 'enriched')->count() / $allCandidates->count(),
                            4,
                        ),
                        'listenbrainz_recommendations' => $beyond === null ? 0 : 1,
                    ],
                ],
            ],
            'recommendations' => $recommendations,
            'configuration' => self::configuration(),
            'lenses' => $lenses,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $candidates */
    private function artistTrail(Collection $candidates): Collection
    {
        $recentSeeds = $candidates
            ->filter(fn (array $item): bool => $item['artist_key'] !== null && $item['artist_preference_eligible']
                && ($item['days_since_heard'] ?? 9999) <= self::REDISCOVER_DAYS)
            ->sortBy('days_since_heard');
        $recommendations = collect();
        foreach ($recentSeeds as $seed) {
            foreach ($candidates->where('artist_key', $seed['artist_key'])->where('id', '!=', $seed['id']) as $candidate) {
                $gapNeed = $candidate['last_heard_at'] === null ? 1 : min(1, max(0, (($candidate['days_since_heard'] ?? 0) - self::RECENTLY_HEARD_DAYS) / 640));
                $score = 0.55 * exp(-($seed['days_since_heard'] ?? 0) / 90) + 0.45 * $gapNeed;
                $recommendation = $this->recommendation($candidate, $score, [[
                    'code' => 'same_artist',
                    'text' => "Another owned album by {$candidate['album']['artist']['name']}, after {$seed['album']['title']}.",
                    'source' => $seed['last_heard_source'] ?? 'plex',
                    'object_entity_id' => $seed['id'],
                ]]);
                if (! $recommendations->has($candidate['id']) || $recommendations->get($candidate['id'])['rank_score'] < $score) {
                    $recommendations->put($candidate['id'], $recommendation);
                }
            }
        }

        return $recommendations->values();
    }

    /** @param array<string, mixed> $item
     * @param  list<array{code:string,text:string,source:string}>  $reasons
     * @return array<string, mixed>
     */
    private function recommendation(array $item, float $score, array $reasons): array
    {
        return [
            'album' => $item['album'],
            'rank_score' => round(min(1, max(0, $score)), 4),
            'reasons' => array_slice($reasons, 0, 3),
            'recently_presented' => (bool) ($item['recently_presented'] ?? false),
        ];
    }

    /** @param array<string, mixed> $recommendation
     * @return array<string, mixed>
     */
    private function publicRecommendation(array $recommendation): array
    {
        unset($recommendation['rank_score'], $recommendation['module_type'], $recommendation['scope'], $recommendation['recently_presented']);
        $recommendation['reasons'] = collect($recommendation['reasons'])
            ->map(fn (array $reason): array => collect($reason)->only(['code', 'text', 'source'])->all())
            ->values()
            ->all();

        return $recommendation;
    }

    /** @return array<string, mixed> */
    public static function configuration(): array
    {
        return [
            'module_order' => ['waiting', 'rediscover', 'recently-heard', 'artist-trail', 'quick-listen', 'recently-added', 'beyond-library'],
            'feature_selection' => 'daily-sha256-confirmed-identity',
            'presentation_cooldown_days' => (int) config('discovery.presentation_cooldown_days', 30),
            'artist_cap_per_module' => (int) config('discovery.artist_cap_per_module', 2),
            'limits' => [
                'section_items' => self::SECTION_ITEMS,
                'section_minimum' => self::SECTION_MINIMUM,
                'recent_artists' => self::RECENT_ARTISTS,
            ],
            'thresholds' => [
                'rediscover_days' => self::REDISCOVER_DAYS,
                'recently_heard_days' => self::RECENTLY_HEARD_DAYS,
                'quick_listen_minutes' => self::QUICK_LISTEN_MINUTES,
            ],
        ];
    }
}
