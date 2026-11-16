<?php

namespace App\Music\Discovery;

use App\Models\DiscoveryBatch;
use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryPick;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DiscoveryBatchGenerator
{
    public function __construct(
        private readonly TasteProfile $taste,
        private readonly DiscoveryProviderStats $stats,
        private readonly ListenBrainzClient $listenBrainz,
        private readonly MusicBrainzClient $musicBrainz,
        private readonly DiscoveryAlbumResolver $albums,
        private readonly ArtistPreferencePolicy $preferences,
    ) {}

    public function generate(string $section): DiscoveryBatch
    {
        if (! in_array($section, ['new_artists', 'new_releases', 'more'], true)) {
            throw new RuntimeException('Unknown discovery section.');
        }
        if (! $this->listenBrainz->configured()) {
            throw new RuntimeException('ListenBrainz not configured.');
        }

        $batch = Cache::lock('disco:discovery-generation:'.$section, 3600)->block(1, function () use ($section): DiscoveryBatch {
            $period = $section === 'new_releases' ? now()->toDateString() : now()->startOfWeek()->toDateString();
            $batch = DiscoveryBatch::query()->firstOrCreate(['section' => $section, 'period' => $period], ['started_at' => now()]);
            if ($batch->status === 'completed' && ($batch->summary['seeds'] ?? -1) !== 0 && ($batch->summary['artists'] ?? -1) !== 0) {
                return $batch;
            }
            $batch->update(['status' => 'running', 'started_at' => now(), 'completed_at' => null]);
            $this->stats->start($section === 'more' ? config('discovery.more_musicbrainz_call_limit', 150) : null);
            try {
                $this->taste->load();
                $exclusions = new DiscoveryExclusions($this->taste, new OwnedLibrary, $this->preferences);
                [$picks, $candidates, $summary] = match ($section) {
                    'new_artists' => $this->newArtists($exclusions),
                    'new_releases' => $this->fresh($exclusions),
                    'more' => $this->more($exclusions),
                };
                DB::transaction(function () use ($batch, $picks, $candidates, $summary, $section): void {
                    if ($section === 'new_artists') {
                        DiscoveryCandidate::query()->delete();
                        foreach ($candidates as $candidate) {
                            DiscoveryCandidate::query()->create([...$candidate, 'batch_id' => $batch->id]);
                        }
                    }
                    foreach ($picks as $position => $pick) {
                        DiscoveryPick::query()->create([...$pick, 'batch_id' => $batch->id, 'position' => $position]);
                    }
                    $batch->update(['status' => 'completed', 'completed_at' => now(), 'summary' => [
                        ...$summary, 'picks' => count($picks),
                        'provider_failures' => array_sum($this->stats->failures),
                        'provider_failure_categories' => $this->stats->failures,
                        'musicbrainz_calls' => $this->stats->musicBrainzCalls,
                    ]]);
                });
            } catch (Throwable $exception) {
                $batch->update(['status' => 'failed', 'completed_at' => now(), 'summary' => ['error' => class_basename($exception), 'provider_failures' => array_sum($this->stats->failures), 'provider_failure_categories' => $this->stats->failures]]);
                throw $exception;
            } finally {
                $this->stats->stop();
            }

            return $batch->refresh();
        });

        app(PickStateReconciler::class)->reconcile();

        return $batch;
    }

    private function newArtists(DiscoveryExclusions $exclusions): array
    {
        $pool = [];
        $seeds = $this->taste->seeds();
        $successfulSeeds = 0;
        foreach ($seeds as $seed) {
            $similar = $this->attempt('listenbrainz.artist_radio', fn () => $this->listenBrainz->artistRadio($seed['mbid']));
            if ($similar === null) {
                continue;
            }
            $successfulSeeds++;
            foreach ($similar as $mbid => $name) {
                if ($exclusions->artist($mbid, $name)) {
                    continue;
                }
                $pool[$mbid] ??= ['artist_mbid' => $mbid, 'artist_name' => $name, 'score' => 0.0, 'seeds' => [], 'listener_count' => null];
                $pool[$mbid]['seeds'][] = $seed;
                $pool[$mbid]['score'] += log1p($seed['score']);
            }
        }
        if ($seeds !== [] && $successfulSeeds === 0) {
            throw new RuntimeException('Artist radio unavailable for every seed.');
        }
        $popularity = $pool === [] ? [] : ($this->attempt('listenbrainz.artist_popularity', fn () => $this->listenBrainz->artistPopularity(array_keys($pool))) ?? []);
        foreach ($pool as $mbid => &$candidate) {
            $listeners = $popularity[$mbid] ?? null;
            $candidate['listener_count'] = $listeners;
            if ($listeners !== null) {
                if ($listeners > config('discovery.max_listener_count', 50000)) {
                    unset($pool[$mbid]);

                    continue;
                }
                $candidate['score'] /= sqrt(1 + $listeners / config('discovery.popular_listener_threshold', 5000));
            }
            usort($candidate['seeds'], fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['mbid'], $b['mbid']));
        }
        unset($candidate);
        uasort($pool, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['artist_mbid'], $b['artist_mbid']));
        $picks = [];
        $credits = [];
        $names = [];
        $lookups = 0;
        foreach ($pool as $candidate) {
            if (count($picks) >= config('discovery.batch_size', 10) || $lookups >= config('discovery.album_lookup_limit', 50)) {
                break;
            }
            $available = array_values(array_filter($candidate['seeds'], fn ($seed) => ($credits[$seed['mbid']] ?? 0) < config('discovery.seed_cap', 2)));
            if ($available === [] || isset($names[Identity::name($candidate['artist_name'])])) {
                continue;
            }
            $lookups++;
            $pick = $this->topAlbum($candidate['artist_mbid'], $candidate['artist_name'], $exclusions);
            if ($pick === null) {
                continue;
            }
            // Every seed named in a reason counts toward its diversity cap.
            $credited = array_slice($available, 0, 2);
            foreach ($credited as $seed) {
                $credits[$seed['mbid']] = ($credits[$seed['mbid']] ?? 0) + 1;
            }
            $names[Identity::name($candidate['artist_name'])] = true;
            $picks[] = [...$pick, 'reason' => 'Similar to '.implode(' and ', array_column($credited, 'name')), 'seeds' => $credited];
        }

        return [$picks, array_values($pool), ['seeds' => count($seeds), 'candidates' => count($pool), 'popularity_available' => $popularity !== []]];
    }

    private function topAlbum(string $mbid, string $name, DiscoveryExclusions $exclusions): ?array
    {
        $rows = $this->attempt('listenbrainz.top_release_groups', fn () => $this->listenBrainz->topReleaseGroups($mbid));
        $rows = collect($rows ?? [])->filter(fn ($row) => is_array($row) && Str::isUuid($row['release_group_mbid'] ?? null))
            ->sortByDesc(fn ($row) => (int) ($row['total_listen_count'] ?? 0))->take(20);
        foreach ($rows as $row) {
            $group = $this->attempt('musicbrainz.release_group', fn () => $this->albums->group($row['release_group_mbid'], $mbid));
            if ($group === null || $exclusions->album($group['id'], $name, $group['title'], $mbid)) {
                continue;
            }
            $pick = $this->attempt('musicbrainz.release', fn () => $this->albums->prepare($group, $mbid, $name, data_get($row, 'release_group.caa_release_mbid')));
            if ($pick !== null) {
                return $pick;
            }
        }

        return null;
    }

    private function fresh(DiscoveryExclusions $exclusions): array
    {
        $days = config('discovery.fresh_days', 30);
        try {
            $personal = $this->listenBrainz->userFreshReleases($days)['payload']['releases'];
        } catch (Throwable $exception) {
            $this->stats->failure('listenbrainz.user_fresh_releases', $exception);
            throw $exception;
        }

        return $this->freshRows($personal, $exclusions, $days);
    }

    private function freshRows(array $personal, DiscoveryExclusions $exclusions, int $days): array
    {
        $candidates = DiscoveryCandidate::query()->get()->keyBy('artist_mbid');
        $pool = $candidates->keys()->all();
        // The combined past and future window can exceed the client's response cap, so fetch each direction separately.
        $sitewide = $pool === [] ? [] : collect([true, false])->flatMap(fn (bool $past) => $this->attempt(
            'listenbrainz.fresh_releases',
            fn () => $this->listenBrainz->freshReleases(now()->toImmutable(), $days <= 30 ? 30 : 60, $past, ! $past),
        )['payload']['releases'] ?? [])->all();
        $rows = [];
        foreach ($personal as $row) {
            $rows[$row['release_group_mbid']] = [...$row, 'tier' => 'personal'];
        }
        foreach ($sitewide as $row) {
            if (array_intersect($row['artist_mbids'], $pool) !== []) {
                $rows[$row['release_group_mbid']] ??= [...$row, 'tier' => 'candidate'];
            }
        }
        $picks = [];
        foreach ($rows as $row) {
            if (count($picks) >= 30) {
                break;
            }
            $artistMbid = $row['artist_mbids'][0];
            $candidate = $candidates->get($artistMbid);
            $artistName = $this->taste->artistName($artistMbid) ?? $candidate?->artist_name
                ?? (count($row['artist_mbids']) === 1 ? $row['artist_credit_name'] : '');
            if (! $this->preferences->allowsIdentity($artistMbid, $artistName)
                || ! in_array(strtolower($row['release_group_primary_type']), ['album', 'ep'], true)
                || array_intersect(array_map('strtolower', $row['release_group_secondary_types']), config('discovery.excluded_secondary_types')) !== []
                || $exclusions->album($row['release_group_mbid'], $artistName, $row['release_name'], $artistMbid)) {
                continue;
            }
            $group = $this->attempt('musicbrainz.release_group', fn () => $this->albums->group($row['release_group_mbid'], $artistMbid));
            if ($group === null) {
                continue;
            }
            $artistName = $artistName ?: (string) data_get($group, 'artist-credit.0.artist.name', '');
            if ($artistName === '' || $exclusions->album($group['id'], $artistName, $group['title'], $artistMbid)) {
                continue;
            }
            $pick = $this->attempt('musicbrainz.release', fn () => $this->albums->prepare($group, $artistMbid, $artistName, $row['caa_release_mbid']));
            if ($pick !== null) {
                $evidence = $candidate ?? $candidates->get(array_values(array_intersect($row['artist_mbids'], $pool))[0] ?? '');
                $seeds = $row['tier'] === 'candidate' ? array_slice($evidence?->seeds ?? [], 0, 2) : [];
                $seeds = array_map(fn ($seed) => [...$seed, 'name' => $this->taste->artistName($seed['mbid']) ?? $seed['name']], $seeds);
                $listens = $this->taste->lifetimeListens($artistMbid, $artistName);
                $reason = $seeds !== [] ? 'Similar to '.implode(' and ', array_column($seeds, 'name'))
                    : ($listens > 0 ? "You've played ".$artistName.' '.$listens.' '.($listens === 1 ? 'time' : 'times')
                        : 'A new release by '.$artistName);
                $picks[] = [...$pick, 'release_date' => $row['release_date'], 'reason' => $reason, 'seeds' => $seeds];
            }
        }

        return [$picks, [], ['personal_releases' => count($personal), 'candidate_releases' => count($rows) - count($personal)]];
    }

    private function more(DiscoveryExclusions $exclusions): array
    {
        $picks = [];
        $names = [];
        $eligibility = new AlbumEligibility;
        foreach ($this->taste->seeds() as $artist) {
            if (count($picks) >= config('discovery.batch_size', 10) || $this->stats->exhausted()) {
                break;
            }
            $normalized = Identity::name($artist['name']);
            if (isset($names[$normalized])) {
                continue;
            }
            $groups = $this->attempt('musicbrainz.release_group', fn () => $this->musicBrainz->artistReleaseGroups($artist['mbid']));
            $groups = collect($groups['release_groups'] ?? [])
                ->filter(fn ($group) => $eligibility->allows($group, $artist['mbid']))
                ->sort(fn ($a, $b) => ((strtolower($a['primary-type']) === 'album' ? 0 : 1) <=> (strtolower($b['primary-type']) === 'album' ? 0 : 1))
                    ?: strcmp($b['first-release-date'] ?? '', $a['first-release-date'] ?? ''));
            foreach ($groups as $row) {
                if ($this->stats->exhausted()) {
                    break 2;
                }
                if ($exclusions->album($row['id'], $artist['name'], $row['title'] ?? '', $artist['mbid'])) {
                    continue;
                }
                $group = $this->attempt('musicbrainz.release_group', fn () => $this->albums->group($row['id'], $artist['mbid']));
                if ($group === null || $exclusions->album($group['id'], $artist['name'], $group['title'], $artist['mbid'])
                    || collect($picks)->contains('release_group_mbid', $group['id'])) {
                    continue;
                }
                $pick = $this->attempt('musicbrainz.release', fn () => $this->albums->prepare($group, $artist['mbid'], $artist['name']));
                if ($pick !== null) {
                    $picks[] = [...$pick, 'reason' => 'An unheard album by '.$artist['name'], 'seeds' => []];
                    $names[$normalized] = true;
                    break;
                }
            }
        }

        return [$picks, [], ['artists' => count($this->taste->seeds())]];
    }

    private function attempt(string $endpoint, callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $exception) {
            $this->stats->failure($endpoint, $exception);
            if (! $exception instanceof ProviderCallBudgetExceeded) {
                Log::warning('Discovery provider request failed.', ['endpoint' => $endpoint, 'error_code' => class_basename($exception)]);
            }

            return null;
        }
    }
}
