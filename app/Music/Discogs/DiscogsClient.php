<?php

namespace App\Music\Discogs;

use App\Music\Http\BoundedResponseBody;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DiscogsClient
{
    public function __construct(private readonly ?string $token = null) {}

    public function configured(): bool
    {
        return $this->resolvedToken(false) !== '';
    }

    /** @return array{id:int|string,username:string} */
    public function authenticatedIdentity(): array
    {
        $timeout = min(60, max(5, (int) config('services.discogs.timeout', 20)));
        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withUserAgent((string) config('services.discogs.user_agent'))
            ->withHeaders(['Authorization' => 'Discogs token='.$this->resolvedToken()])
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])
            ->connectTimeout(10)
            ->timeout($timeout)
            ->get('/oauth/identity');
        $body = BoundedResponseBody::read($response, 64 * 1024, 'Discogs identity response exceeded its safety limit.', $timeout);
        $response->throw();
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'json')) {
            throw new RuntimeException('Discogs returned an unexpected identity content type.');
        }
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        $username = is_array($payload) ? ($payload['username'] ?? null) : null;
        if ((! is_int($id) && ! is_string($id)) || ! is_string($username) || trim($username) === ''
            || strlen($username) > 255 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new RuntimeException('Discogs returned an invalid authenticated identity.');
        }

        return ['id' => $id, 'username' => $username];
    }

    /** @return array<string, mixed> */
    public function catalogObject(string $type, string $id): array
    {
        if (! in_array($type, ['artist', 'master', 'release'], true)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1) {
            throw new RuntimeException('Invalid Discogs catalog request.');
        }
        $token = $this->resolvedToken();

        $timeout = min(60, max(5, (int) config('services.discogs.timeout', 20)));
        $lockSeconds = max(60, 3 * ($timeout + 5));
        [$response, $body] = Cache::lock('disco:discogs:request', $lockSeconds)->block($lockSeconds, function () use ($id, $timeout, $token, $type): array {
            [$response, $body] = retry(3, function () use ($id, $timeout, $token, $type): array {
                $response = Http::baseUrl($this->baseUrl())
                    ->accept('application/vnd.discogs.v2.plaintext+json')
                    ->withUserAgent((string) config('services.discogs.user_agent'))
                    ->withHeaders(['Authorization' => "Discogs token={$token}"])
                    ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])
                    ->connectTimeout(10)
                    ->timeout($timeout)
                    ->get("/{$type}s/{$id}");
                if (in_array($response->status(), [429, 502, 503], true)) {
                    $response->toPsrResponse()->getBody()->close();
                    $response->throw();
                }
                $body = BoundedResponseBody::read($response, 2 * 1024 * 1024, 'Discogs response exceeded its safety limit.', $timeout);
                $response->throw();

                return [$response, $body];
            }, 1500, fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), [429, 502, 503], true));
            $interval = min(5000, max(0, (int) config('services.discogs.rate_interval_ms', 1100)));
            if ($interval > 0) {
                usleep($interval * 1000);
            }

            return [$response, $body];
        });
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'json')) {
            throw new RuntimeException('Discogs returned an unexpected content type.');
        }
        $payload = json_decode($body, true, 256, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || (string) ($payload['id'] ?? '') !== $id) {
            throw new RuntimeException('Discogs returned an unexpected catalog object.');
        }

        return $payload;
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.discogs.url'), '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('DISCOGS_URL must be a trusted HTTPS URL.');
        }

        return $url;
    }

    private function resolvedToken(bool $required = true): string
    {
        $token = trim($this->token ?? (string) config('services.discogs.token'));
        if ($required && $token === '') {
            throw new RuntimeException('DISCOGS_TOKEN is not configured.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new RuntimeException('DISCOGS_TOKEN is invalid.');
        }

        return $token;
    }
}
