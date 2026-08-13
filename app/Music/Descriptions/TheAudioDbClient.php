<?php

namespace App\Music\Descriptions;

use App\Music\Http\BoundedResponseBody;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TheAudioDbClient
{
    public function __construct(private readonly ?string $apiKey = null) {}

    /** @return array<string, mixed>|null */
    public function album(string $releaseGroupMbid): ?array
    {
        return $this->entity('album-mb.php', 'album', 'album', $releaseGroupMbid);
    }

    /** @return array<string, mixed>|null */
    public function artist(string $artistMbid): ?array
    {
        return $this->entity('artist-mb.php', 'artists', 'artist', $artistMbid);
    }

    /** @return array<string, mixed>|null */
    private function entity(string $endpoint, string $payloadKey, string $type, string $mbid): ?array
    {
        $mbid = strtolower($mbid);
        if (! Str::isUuid($mbid)) {
            throw new RuntimeException("Invalid TheAudioDB {$type} identifier.");
        }
        $key = $this->apiKey ?? (string) config('services.theaudiodb.api_key', '123');
        if (preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $key) !== 1) {
            throw new RuntimeException('THEAUDIODB_API_KEY is invalid.');
        }
        $timeout = (int) config('services.theaudiodb.timeout', 30);
        $lockSeconds = max(180, 3 * (3 * $timeout + 10) + 10);
        [$response, $body] = Cache::lock('disco:theaudiodb:request', $lockSeconds)->block($lockSeconds, function () use ($endpoint, $key, $mbid, $timeout, $type) {
            [$response, $body] = retry(3, function () use ($endpoint, $key, $mbid, $timeout, $type): array {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('services.theaudiodb.user_agent'))
                    ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])
                    ->connectTimeout(10)
                    ->timeout($timeout)
                    ->get($this->baseUrl()."/{$key}/{$endpoint}", ['i' => $mbid]);
                if (in_array($response->status(), [429, 503], true)) {
                    $response->toPsrResponse()->getBody()->close();
                    $response->throw();
                }
                $body = BoundedResponseBody::read($response, 3 * 1024 * 1024, "TheAudioDB returned an invalid {$type} response.", $timeout);
                $response->throw();

                return [$response, $body];
            }, 2500, fn (?Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [429, 503], true));
            $interval = max(2100, (int) config('services.theaudiodb.rate_interval_ms', 2100));
            usleep($interval * 1000);

            return [$response, $body];
        });
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'json')) {
            throw new RuntimeException("TheAudioDB returned an invalid {$type} response.");
        }
        $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! array_key_exists($payloadKey, $payload)) {
            throw new RuntimeException("TheAudioDB returned an invalid {$type} response.");
        }
        $entities = $payload[$payloadKey];
        if ($entities === null || $entities === []) {
            return null;
        }
        if (! is_array($entities) || ! array_is_list($entities) || count($entities) !== 1 || ! is_array($entities[0])) {
            throw new RuntimeException("TheAudioDB returned an invalid {$type} response.");
        }
        $entity = $entities[0];
        if (! is_array($entity) || strtolower((string) ($entity['strMusicBrainzID'] ?? '')) !== $mbid) {
            throw new RuntimeException("TheAudioDB returned another {$type} identity.");
        }

        return $entity;
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.theaudiodb.url'), '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'www.theaudiodb.com'
            || ($parts['path'] ?? '') !== '/api/v1/json' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('THEAUDIODB_URL must be the trusted HTTPS v1 API root.');
        }

        return $url;
    }
}
