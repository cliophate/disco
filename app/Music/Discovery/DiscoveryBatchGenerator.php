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
    private int $failures = 0;

    public function __construct(
        private readonly TasteProfile $taste,
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

        return Cache::lock('disco:discovery-generation', 21600)->block(1, function () use ($section): DiscoveryBatch {
            $period = $section === 'new_releases' ? now()->toDateString() : now()->startOfWeek()->toDateString();
            $batch = DiscoveryBatch::query()->firstOrCreate(['section' => $section, 'period' => $period], ['started_at' => now()]);
            if ($batch->status === 'completed' && ($batch->summary['seeds'] ?? -1) !== 0 && ($batch->summary['artists'] ?? -1) !== 0) {
                return $batch;
            }
            $batch->update(['status' => 'running', 'started_at' => now(), 'completed_at' => null]);
            $this->failures = 0;
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
                        ...$summary, 'picks' => count($picks), 'provider_failures' => $this->failures,
                    ]]);
                });
            } catch (Throwable $exception) {
                $batch->update(['status' => 'failed', 'completed_at' => now(), 'summary' => ['error' => class_basename($exception)]]);
                throw $exception;
            }

            app(PickStateReconciler::class)->reconcile();

            return $batch->refresh();
        });
    }

    private function newArtists(DiscoveryExclusions $exclusions): array
    {
        $pool = [];
        $seeds = $this->taste->seeds();
        $successfulSeeds = 0;
        foreach ($seeds as $seed) {
            $similar = $this->attempt(fn () => $this->listenBrainz->artistRadio($seed['mbid']));
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
        $popularity = $pool === [] ? [] : ($this->attempt(fn () => $this->listenBrainz->artistPopularity(array_keys($pool))) ?? []);
        foreach ($pool as $mbid => &$candidate) {
            $listeners = $popularity[$mbid] ?? null;
            $candidate['listener_count'] = $listeners;
            if ($listeners !== null) {
                $excess = max(0, $listeners / config('discovery.popular_listener_threshold', 10000) - 1);
                $candidate['score'] /= 1 + config('discovery.popularity_penalty', 0.5) * log1p($excess);
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
        $rows = $this->attempt(fn () => $this->listenBrainz->topReleaseGroups($mbid));
        $rows = collect($rows ?? [])->filter(fn ($row) => is_array($row) && Str::isUuid($row['release_group_mbid'] ?? null))
            ->sortByDesc(fn ($row) => (int) ($row['total_listen_count'] ?? 0))->take(20);
        foreach ($rows as $row) {
            $group = $this->attempt(fn () => $this->albums->group($row['release_group_mbid'], $mbid));
            if ($group === null || $exclusions->album($group['id'], $name, $group['title'], false, $mbid)) {
                continue;
            }
            $pick = $this->attempt(fn () => $this->albums->prepare($group, $mbid, $name, data_get($row, 'release_group.caa_release_mbid')));
            if ($pick !== null) {
                return $pick;
            }
        }

        return null;
    }

    private function fresh(DiscoveryExclusions $exclusions): array
    {
        $days = config('discovery.fresh_days', 30);
        $personal = $this->listenBrainz->userFreshReleases($days)['payload']['releases'];

        return $this->freshRows($personal, $exclusions, $days);
    }

    private function freshRows(array $personal, DiscoveryExclusions $exclusions, int $days): array
    {
        $pool = DiscoveryCandidate::query()->pluck('artist_mbid')->all();
        $sitewide = $pool === [] ? [] : ($this->attempt(fn () => $this->listenBrainz->freshReleases(now()->toImmutable(), $days <= 30 ? 30 : 60, true, true))['payload']['releases'] ?? []);
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
            if (! $this->preferences->allowsIdentity($artistMbid, $row['artist_credit_name'])
                || ! in_array(strtolower($row['release_group_primary_type']), ['album', 'ep'], true)
                || array_intersect(array_map('strtolower', $row['release_group_secondary_types']), config('discovery.excluded_secondary_types')) !== []
                || $exclusions->album($row['release_group_mbid'], $row['artist_credit_name'], $row['release_name'], false, $artistMbid)) {
                continue;
            }
            $group = $this->attempt(fn () => $this->albums->group($row['release_group_mbid'], $artistMbid));
            if ($group === null) {
                continue;
            }
            $pick = $this->attempt(fn () => $this->albums->prepare($group, $artistMbid, $row['artist_credit_name'], $row['caa_release_mbid']));
            if ($pick !== null) {
                $picks[] = [...$pick, 'release_date' => $row['release_date'],
                    'reason' => $row['tier'] === 'personal' ? 'New music from your listening history' : "New music from this week's discoveries", 'seeds' => []];
            }
        }

        return [$picks, [], ['personal_releases' => count($personal), 'candidate_releases' => count($rows) - count($personal)]];
    }

    private function more(DiscoveryExclusions $exclusions): array
    {
        $picks = [];
        $lookups = 0;
        foreach ($this->taste->seeds() as $artist) {
            $groups = $this->attempt(fn () => $this->musicBrainz->artistReleaseGroups($artist['mbid']));
            $groups = collect($groups['release_groups'] ?? [])->sortByDesc(fn ($group) => $group['first-release-date'] ?? '');
            foreach ($groups as $row) {
                if (count($picks) >= config('discovery.batch_size', 10) || $lookups >= config('discovery.album_lookup_limit', 50)) {
                    break 2;
                }
                if ($exclusions->album($row['id'], $artist['name'], $row['title'] ?? '', true, $artist['mbid'])) {
                    continue;
                }
                $lookups++;
                $group = $this->attempt(fn () => $this->albums->group($row['id'], $artist['mbid']));
                if ($group === null || $exclusions->album($group['id'], $artist['name'], $group['title'], true, $artist['mbid'])
                    || collect($picks)->contains('release_group_mbid', $group['id'])) {
                    continue;
                }
                $pick = $this->attempt(fn () => $this->albums->prepare($group, $artist['mbid'], $artist['name']));
                if ($pick !== null) {
                    $picks[] = [...$pick, 'reason' => 'An unheard album by '.$artist['name'], 'seeds' => []];
                    break;
                }
            }
        }

        return [$picks, [], ['artists' => count($this->taste->seeds())]];
    }

    private function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $exception) {
            $this->failures++;
            Log::warning('Discovery provider request failed.', ['error_code' => class_basename($exception)]);

            return null;
        }
    }
}
