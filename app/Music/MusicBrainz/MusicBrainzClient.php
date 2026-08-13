<?php

namespace App\Music\MusicBrainz;

use App\Music\Http\BoundedResponseBody;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MusicBrainzClient
{
    /** @return array<string, mixed> */
    public function entity(string $type, string $mbid): array
    {
        if (! Str::isUuid($mbid) || ! in_array($type, ['artist', 'recording', 'release', 'release-group', 'work'], true)) {
            throw new RuntimeException('Invalid MusicBrainz entity request.');
        }

        $includes = match ($type) {
            'artist' => 'genres+url-rels',
            'recording' => 'artist-credits+releases+release-groups+isrcs+artist-rels+work-rels',
            'release' => 'release-groups+artist-credits+labels+recordings+url-rels+artist-rels',
            'release-group' => 'artist-credits+genres+releases+url-rels+artist-rels',
            'work' => 'artist-rels',
        };
        $payload = $this->request("/{$type}/{$mbid}", ['inc' => $includes, 'fmt' => 'json']);
        if (($payload['id'] ?? null) !== $mbid) {
            throw new RuntimeException('MusicBrainz returned an unexpected entity.');
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    public function searchReleaseGroups(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2 || mb_strlen($query) > 120 || preg_match('/[\x00-\x1F\x7F]/', $query) === 1
            || $limit < 1 || $limit > 25) {
            throw new RuntimeException('Invalid MusicBrainz release-group search.');
        }

        return Cache::remember('disco:musicbrainz:release-group-search:'.hash('sha256', mb_strtolower($query).":{$limit}"), now()->addHour(), function () use ($limit, $query): array {
            $payload = $this->request('/release-group', [
                'query' => $query,
                'limit' => $limit,
                'offset' => 0,
                'fmt' => 'json',
            ], 2 * 1024 * 1024);
            $results = $payload['release-groups'] ?? null;
            if (! is_array($results) || count($results) > $limit) {
                throw new RuntimeException('MusicBrainz returned an invalid release-group search.');
            }

            return array_values(array_filter($results, 'is_array'));
        });
    }

    /** @return list<array<string, mixed>> */
    public function releaseGroupReleases(string $releaseGroupMbid, int $limit = 100): array
    {
        $releaseGroupMbid = strtolower($releaseGroupMbid);
        if (! Str::isUuid($releaseGroupMbid) || $limit < 1 || $limit > 100) {
            throw new RuntimeException('Invalid MusicBrainz release-group browse.');
        }
        $payload = $this->request('/release', [
            'release-group' => $releaseGroupMbid,
            'inc' => 'release-groups',
            'limit' => $limit,
            'offset' => 0,
            'fmt' => 'json',
        ], 2 * 1024 * 1024);
        $releases = $payload['releases'] ?? null;
        if (! is_array($releases) || count($releases) > $limit) {
            throw new RuntimeException('MusicBrainz returned an invalid release-group browse.');
        }
        foreach ($releases as $release) {
            if (! is_array($release) || ! Str::isUuid($release['id'] ?? null)
                || strtolower((string) data_get($release, 'release-group.id')) !== $releaseGroupMbid) {
                throw new RuntimeException('MusicBrainz returned a release from another release group.');
            }
        }

        return array_values($releases);
    }

    /** @return array{release_groups:list<array<string,mixed>>,total:int,pages:int,truncated:bool} */
    public function artistReleaseGroups(string $artistMbid, int $pageSize = 100, int $maxPages = 3): array
    {
        $artistMbid = strtolower($artistMbid);
        if (! Str::isUuid($artistMbid) || $pageSize < 1 || $pageSize > 100 || $maxPages < 1 || $maxPages > 10) {
            throw new RuntimeException('Invalid MusicBrainz artist release-group browse.');
        }

        $groups = [];
        $total = 0;
        $expectedTotal = null;
        $pages = 0;
        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $pageSize;
            $payload = $this->request('/release-group', [
                'artist' => $artistMbid,
                'inc' => 'artist-credits',
                'limit' => $pageSize,
                'offset' => $offset,
                'fmt' => 'json',
            ], 5 * 1024 * 1024);
            $pageGroups = $payload['release-groups'] ?? null;
            $count = $payload['release-group-count'] ?? null;
            if (! is_array($pageGroups) || count($pageGroups) > $pageSize || ! is_numeric($count) || (int) $count < 0) {
                throw new RuntimeException('MusicBrainz returned an invalid artist release-group browse.');
            }
            if (($payload['release-group-offset'] ?? $offset) !== $offset
                || ($expectedTotal !== null && $expectedTotal !== (int) $count)) {
                throw new RuntimeException('MusicBrainz artist release-group pagination changed during the browse.');
            }
            $expectedTotal ??= (int) $count;
            foreach ($pageGroups as $group) {
                if (! is_array($group) || ! Str::isUuid($group['id'] ?? null)) {
                    throw new RuntimeException('MusicBrainz returned an invalid release-group identity.');
                }
                $groups[] = $group;
            }
            $total = (int) $count;
            $pages++;
            if ($pageGroups === [] || $offset + count($pageGroups) >= $total) {
                break;
            }
        }

        return [
            'release_groups' => $groups,
            'total' => $total,
            'pages' => $pages,
            'truncated' => count($groups) < $total,
        ];
    }

    /** @return array<string,mixed>|null */
    public function officialRelease(string $releaseGroupMbid, int $pageSize = 100, int $maxPages = 2): ?array
    {
        $releaseGroupMbid = strtolower($releaseGroupMbid);
        if (! Str::isUuid($releaseGroupMbid) || $pageSize < 1 || $pageSize > 100 || $maxPages < 1 || $maxPages > 5) {
            throw new RuntimeException('Invalid MusicBrainz official-release browse.');
        }

        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $pageSize;
            $payload = $this->request('/release', [
                'release-group' => $releaseGroupMbid,
                'inc' => 'release-groups',
                'limit' => $pageSize,
                'offset' => $offset,
                'fmt' => 'json',
            ], 2 * 1024 * 1024);
            $releases = $payload['releases'] ?? null;
            $total = $payload['release-count'] ?? count(is_array($releases) ? $releases : []);
            if (! is_array($releases) || count($releases) > $pageSize || ! is_numeric($total)) {
                throw new RuntimeException('MusicBrainz returned an invalid official-release browse.');
            }
            foreach ($releases as $release) {
                if (! is_array($release) || ! Str::isUuid($release['id'] ?? null)
                    || strtolower((string) data_get($release, 'release-group.id')) !== $releaseGroupMbid) {
                    throw new RuntimeException('MusicBrainz returned a release from another release group.');
                }
                if (strtolower((string) ($release['status'] ?? '')) === 'official') {
                    return $release;
                }
            }
            if ($releases === [] || $offset + count($releases) >= (int) $total) {
                return null;
            }
        }

        throw new RuntimeException('MusicBrainz official-release browse exceeded its bounded page limit.');
    }

    /** @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query, int $maxBytes = 5242880): array
    {
        $timeout = (int) config('services.musicbrainz.timeout', 30);
        $lockSeconds = max(180, 3 * (3 * $timeout + 10) + 10);
        [$response, $body] = Cache::lock('disco:musicbrainz:request', $lockSeconds)->block($lockSeconds, function () use ($maxBytes, $path, $query, $timeout) {
            [$response, $body] = retry(3, function () use ($maxBytes, $path, $query, $timeout): array {
                $response = Http::baseUrl($this->baseUrl())
                    ->acceptJson()
                    ->withUserAgent((string) config('services.musicbrainz.user_agent'))
                    ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])
                    ->connectTimeout(10)
                    ->timeout($timeout)
                    ->get($path, $query);
                if (in_array($response->status(), [429, 503], true)) {
                    $response->toPsrResponse()->getBody()->close();
                    $response->throw();
                }
                $body = BoundedResponseBody::read($response, $maxBytes, 'MusicBrainz response exceeded its safety limit.', $timeout);
                $response->throw();

                return [$response, $body];
            }, 1500, fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [429, 503], true));
            $interval = (int) config('services.musicbrainz.rate_interval_ms', 1100);
            if ($interval > 0) {
                usleep($interval * 1000);
            }

            return [$response, $body];
        });
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'json')) {
            throw new RuntimeException('MusicBrainz returned an unexpected content type.');
        }
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('MusicBrainz returned an unexpected payload.');
        }

        return $payload;
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.musicbrainz.url'), '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('MUSICBRAINZ_URL must be a trusted HTTPS URL.');
        }

        return $url;
    }
}
