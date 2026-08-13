<?php

namespace App\Music\ListenBrainz;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class ListenBrainzClient
{
    private const MAX_FRESH_RELEASES = 10_000;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $username = null,
        private readonly ?string $token = null,
    ) {}

    public function configured(): bool
    {
        return filled($this->username ?? config('services.listenbrainz.username'))
            && filled($this->token ?? config('services.listenbrainz.token'));
    }

    public function redactSecret(string $message): string
    {
        $token = $this->token ?? (string) config('services.listenbrainz.token');

        return $token === '' ? $message : str_replace($token, '[redacted]', $message);
    }

    public function validateToken(): void
    {
        $payload = $this->get('/1/validate-token');
        $username = $this->resolvedUsername();

        if (($payload['valid'] ?? null) !== true
            || ! is_string($payload['user_name'] ?? null)
            || ! hash_equals($username, $payload['user_name'])) {
            throw new RuntimeException('ListenBrainz token validation did not bind to the configured username.');
        }
    }

    /** @return array{payload:array{user_id:string,listens:list<array<string,mixed>>}} */
    public function listens(?int $minTimestamp, ?int $maxTimestamp, int $count): array
    {
        if ($count < 1 || $count > 1000 || ($minTimestamp !== null && $minTimestamp < 0)
            || ($maxTimestamp !== null && $maxTimestamp < 0)
            || ($minTimestamp !== null && $maxTimestamp !== null)) {
            throw new RuntimeException('Invalid ListenBrainz listens request.');
        }

        $query = ['count' => $count];
        if ($minTimestamp !== null) {
            $query['min_ts'] = $minTimestamp;
        }
        if ($maxTimestamp !== null) {
            $query['max_ts'] = $maxTimestamp;
        }

        $username = $this->resolvedUsername();
        $response = $this->get('/1/user/'.rawurlencode($username).'/listens', $query);
        $payload = $response['payload'] ?? null;
        if (! is_array($payload) || ! is_string($payload['user_id'] ?? null)
            || ! hash_equals($username, $payload['user_id']) || ! is_array($payload['listens'] ?? null)
            || ! array_is_list($payload['listens'])) {
            throw new RuntimeException('ListenBrainz returned an invalid listens payload.');
        }

        $previousTimestamp = null;
        foreach ($payload['listens'] as $listen) {
            if (! is_array($listen) || ! is_int($listen['listened_at'] ?? null)
                || $listen['listened_at'] < 0 || ! is_array($listen['track_metadata'] ?? null)
                || ! is_string(data_get($listen, 'track_metadata.artist_name'))
                || ! is_string(data_get($listen, 'track_metadata.track_name'))
                || (isset($listen['track_metadata']['release_name']) && ! is_string($listen['track_metadata']['release_name']))
                || (isset($listen['track_metadata']['additional_info']) && ! is_array($listen['track_metadata']['additional_info']))
                || (isset($listen['track_metadata']['mbid_mapping']) && ! is_array($listen['track_metadata']['mbid_mapping']))) {
                throw new RuntimeException('ListenBrainz returned an invalid listen record.');
            }
            if ($previousTimestamp !== null && $listen['listened_at'] > $previousTimestamp) {
                throw new RuntimeException('ListenBrainz listens were not ordered newest first.');
            }
            if (($minTimestamp !== null && $listen['listened_at'] <= $minTimestamp)
                || ($maxTimestamp !== null && $listen['listened_at'] >= $maxTimestamp)) {
                throw new RuntimeException('ListenBrainz returned a listen outside the requested exclusive bounds.');
            }
            $previousTimestamp = $listen['listened_at'];
        }

        /** @var array{payload:array{user_id:string,listens:list<array<string,mixed>>}} $response */
        return $response;
    }

    /** @return array{payload:array{count:int,entity:string,last_updated:?int,model_id:?string,user_name:string,mbids:list<array{recording_mbid:string,score:float,latest_listened_at:?string}>}}|null */
    public function recordingRecommendations(int $count, int $offset = 0): ?array
    {
        if ($count < 1 || $count > 1000 || $offset < 0) {
            throw new RuntimeException('Invalid ListenBrainz recommendation request.');
        }
        $username = $this->resolvedUsername();
        $httpResponse = $this->request(authenticated: false)->get(
            '/1/cf/recommendation/user/'.rawurlencode($username).'/recording',
            ['count' => $count, 'offset' => $offset],
        );
        if ($httpResponse->status() === 204) {
            return null;
        }
        $response = $this->decode($httpResponse->throw());
        $payload = $response['payload'] ?? null;
        if (! is_array($payload) || ($payload['entity'] ?? null) !== 'recording'
            || ! is_string($payload['user_name'] ?? null) || ! hash_equals($username, $payload['user_name'])
            || ! is_array($payload['mbids'] ?? null) || ! array_is_list($payload['mbids'])) {
            throw new RuntimeException('ListenBrainz returned an invalid recommendation payload.');
        }
        $recommendations = [];
        foreach ($payload['mbids'] as $record) {
            $mbid = is_array($record) && array_is_list($record) ? ($record[0] ?? null) : ($record['recording_mbid'] ?? null);
            $score = is_array($record) && array_is_list($record) ? ($record[1] ?? null) : ($record['score'] ?? null);
            $latestListenedAt = is_array($record) && ! array_is_list($record) ? ($record['latest_listened_at'] ?? null) : null;
            if (! is_string($mbid) || ! Str::isUuid($mbid) || ! is_numeric($score)
                || ! is_finite((float) $score) || ($latestListenedAt !== null && ! is_string($latestListenedAt))) {
                throw new RuntimeException('ListenBrainz returned an invalid recommendation record.');
            }
            $recommendations[] = [
                'recording_mbid' => strtolower($mbid),
                'score' => (float) $score,
                'latest_listened_at' => $latestListenedAt,
            ];
        }
        if (isset($payload['count']) && (! is_int($payload['count']) || $payload['count'] !== count($recommendations))) {
            throw new RuntimeException('ListenBrainz recommendation count did not match its payload.');
        }
        if (count($recommendations) > $count) {
            throw new RuntimeException('ListenBrainz returned more recommendations than requested.');
        }

        return ['payload' => [
            'count' => count($recommendations),
            'entity' => 'recording',
            'last_updated' => is_int($payload['last_updated'] ?? null) ? $payload['last_updated'] : null,
            'model_id' => is_string($payload['model_id'] ?? null) ? $payload['model_id'] : null,
            'user_name' => $username,
            'mbids' => $recommendations,
        ]];
    }

    /**
     * @return array{payload:array{total_count:int,releases:list<array{
     *     artist_credit_name:string,artist_mbids:list<string>,release_date:string,
     *     release_group_mbid:string,release_group_primary_type:string,
     *     release_group_secondary_types:list<string>,release_mbid:string,release_name:string,
     *     caa_id:?string,caa_release_mbid:?string,listen_count:int,release_tags:list<string>
     * }>}}
     */
    public function freshReleases(CarbonImmutable $releaseDate, int $days, bool $past = false, bool $future = true): array
    {
        if (! in_array($days, [30, 60], true)) {
            throw new RuntimeException('Invalid ListenBrainz fresh-release horizon.');
        }
        if (! $past && ! $future) {
            throw new RuntimeException('A fresh-release direction is required.');
        }

        $httpResponse = $this->request(authenticated: false)->get('/1/explore/fresh-releases/', [
            'release_date' => $releaseDate->toDateString(),
            'days' => $days,
            'sort' => 'release_date',
            'past' => $past ? 'true' : 'false',
            'future' => $future ? 'true' : 'false',
        ]);
        $response = $this->decode($httpResponse->throw());
        $payload = $response['payload'] ?? null;
        if (! is_array($payload) || ! is_int($payload['total_count'] ?? null)
            || $payload['total_count'] < 0 || ! is_array($payload['releases'] ?? null)
            || ! array_is_list($payload['releases']) || count($payload['releases']) > self::MAX_FRESH_RELEASES) {
            throw new RuntimeException('ListenBrainz returned an invalid fresh-releases payload.');
        }

        $minimumDate = $past ? $releaseDate->subDays($days) : $releaseDate;
        $maximumDate = $future ? $releaseDate->addDays($days) : $releaseDate;
        $releases = [];
        foreach ($payload['releases'] as $release) {
            // Fresh Releases includes untyped groups; they cannot satisfy the Album/EP contract.
            if (is_array($release) && ($release['release_group_primary_type'] ?? null) === null) {
                continue;
            }
            $date = null;
            if (is_array($release) && is_string($release['release_date'] ?? null)
                && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $release['release_date']) === 1) {
                try {
                    $date = CarbonImmutable::createFromFormat('!Y-m-d', $release['release_date']);
                } catch (Throwable) {
                    // The common validation block below reports malformed provider records.
                }
            }
            $artistMbids = is_array($release['artist_mbids'] ?? null) && array_is_list($release['artist_mbids'])
                ? $release['artist_mbids']
                : null;
            $secondary = $release['release_group_secondary_type'] ?? null;
            $secondaryTypes = $secondary === null ? [] : (is_string($secondary) && strlen($secondary) <= 80 ? [$secondary] : null);
            $caaId = $release['caa_id'] ?? null;
            $caaReleaseMbid = $release['caa_release_mbid'] ?? null;
            $listenCount = $release['listen_count'] ?? 0;
            $tags = $release['release_tags'] ?? [];
            if (! is_array($release) || ! is_string($release['artist_credit_name'] ?? null)
                || trim($release['artist_credit_name']) === '' || strlen($release['artist_credit_name']) > 255
                || $artistMbids === null || $artistMbids === [] || count($artistMbids) > 50
                || collect($artistMbids)->contains(fn ($mbid): bool => ! is_string($mbid) || ! Str::isUuid($mbid))
                || $date === null || $date->toDateString() !== $release['release_date']
                || $date->isBefore($minimumDate) || $date->isAfter($maximumDate)
                || ! is_string($release['release_group_mbid'] ?? null) || ! Str::isUuid($release['release_group_mbid'])
                || ! is_string($release['release_mbid'] ?? null) || ! Str::isUuid($release['release_mbid'])
                || ! is_string($release['release_group_primary_type'] ?? null) || strlen($release['release_group_primary_type']) > 32
                || ! is_string($release['release_name'] ?? null) || trim($release['release_name']) === ''
                || strlen($release['release_name']) > 255 || $secondaryTypes === null
                || ($caaId !== null && (! is_int($caaId) && ! is_string($caaId)))
                || ($caaId !== null && preg_match('/\A[0-9]{1,32}\z/', (string) $caaId) !== 1)
                || ($caaReleaseMbid !== null && (! is_string($caaReleaseMbid) || ! Str::isUuid($caaReleaseMbid)))
                || ! is_int($listenCount) || $listenCount < 0
                || ! is_array($tags) || ! array_is_list($tags)
                || count($tags) > 100 || collect($tags)->contains(fn ($tag): bool => ! is_string($tag) || strlen($tag) > 100)) {
                continue;
            }

            $releases[] = [
                'artist_credit_name' => trim($release['artist_credit_name']),
                'artist_mbids' => collect($artistMbids)->map(fn (string $mbid): string => strtolower($mbid))->unique()->values()->all(),
                'release_date' => $date->toDateString(),
                'release_group_mbid' => strtolower($release['release_group_mbid']),
                'release_group_primary_type' => $release['release_group_primary_type'],
                'release_group_secondary_types' => $secondaryTypes,
                'release_mbid' => strtolower($release['release_mbid']),
                'release_name' => trim($release['release_name']),
                'caa_id' => $caaId === null ? null : (string) $caaId,
                'caa_release_mbid' => $caaReleaseMbid === null ? null : strtolower($caaReleaseMbid),
                'listen_count' => $listenCount,
                'release_tags' => collect($tags)->map('trim')->filter()->unique()->take(20)->values()->all(),
            ];
        }

        return ['payload' => ['total_count' => $payload['total_count'], 'releases' => $releases]];
    }

    /** @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->decode($this->request()->get($path, $query)->throw());
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'application/json')) {
            throw new RuntimeException('ListenBrainz returned an unexpected content type.');
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(64 * 1024);
            if (strlen($body) > 10 * 1024 * 1024) {
                $stream->close();

                throw new RuntimeException('ListenBrainz response exceeded the 10 MiB safety limit.');
            }
        }
        $stream->close();

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('ListenBrainz returned invalid JSON.', previous: $exception);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('ListenBrainz returned an invalid JSON object.');
        }

        return $payload;
    }

    private function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl($this->resolvedBaseUrl())
            ->acceptJson()
            ->withUserAgent((string) config('services.listenbrainz.user_agent'))
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->connectTimeout(10)
            ->timeout((int) config('services.listenbrainz.timeout', 30))
            ->retry([500, 1000, 2000, 4000], fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 503], true)));

        return $authenticated
            ? $request->withHeaders(['Authorization' => 'Token '.$this->resolvedToken()])
            : $request;
    }

    private function resolvedBaseUrl(): string
    {
        $url = rtrim($this->baseUrl ?? (string) config('services.listenbrainz.url'), '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('LISTENBRAINZ_URL must be an exact HTTPS origin.');
        }

        return $url;
    }

    private function resolvedUsername(): string
    {
        $username = $this->username ?? (string) config('services.listenbrainz.username');
        if ($username === '' || strlen($username) > 255 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new RuntimeException('LISTENBRAINZ_USERNAME is not configured correctly.');
        }

        return $username;
    }

    private function resolvedToken(): string
    {
        $token = $this->token ?? (string) config('services.listenbrainz.token');
        if ($token === '' || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new RuntimeException('LISTENBRAINZ_TOKEN is not configured correctly.');
        }

        return $token;
    }
}
