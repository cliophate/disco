<?php

namespace App\Music\CoverArtArchive;

use App\Music\Http\BoundedResponseBody;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CoverArtArchiveClient
{
    /** @return array{release_mbid:string,image_id:string}|null */
    public function front(string $releaseMbid): ?array
    {
        $releaseMbid = strtolower($releaseMbid);
        if (! Str::isUuid($releaseMbid)) {
            throw new RuntimeException('Invalid Cover Art Archive release identifier.');
        }
        [$response, $body] = $this->request($this->baseUrl()."/release/{$releaseMbid}", 'application/json', 1024 * 1024, 'Cover Art Archive returned an invalid metadata response.');
        for ($redirects = 0; $response->redirect() && $redirects < 3; $redirects++) {
            $location = $response->header('Location');
            $this->assertArchiveUrl($location, $releaseMbid);
            [$response, $body] = $this->request($location, 'application/json', 1024 * 1024, 'Cover Art Archive returned an invalid metadata response.');
        }
        if ($response->redirect()) {
            throw new RuntimeException('Cover Art Archive metadata exceeded the redirect limit.');
        }
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();
        if (! str_contains(strtolower($response->header('Content-Type', '')), 'json')) {
            throw new RuntimeException('Cover Art Archive returned an invalid metadata response.');
        }
        $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($payload['images'] ?? null) || count($payload['images']) > 100) {
            throw new RuntimeException('Cover Art Archive returned malformed metadata.');
        }
        $sourceRelease = is_string($payload['release'] ?? null) ? basename(parse_url($payload['release'], PHP_URL_PATH) ?: '') : '';
        if (strtolower($sourceRelease) !== $releaseMbid) {
            throw new RuntimeException('Cover Art Archive returned metadata for another release.');
        }
        $image = collect($payload['images'])->filter(fn ($candidate): bool => is_array($candidate)
            && ($candidate['approved'] ?? false) === true
            && (($candidate['front'] ?? false) === true || in_array('Front', $candidate['types'] ?? [], true))
            && is_scalar($candidate['id'] ?? null)
            && strlen((string) $candidate['id']) <= 40
            && preg_match('/\A[0-9]+\z/', (string) $candidate['id']) === 1)
            ->sort(fn (array $left, array $right): int => strlen((string) $left['id']) <=> strlen((string) $right['id'])
                ?: strcmp((string) $left['id'], (string) $right['id']))->first();

        return $image === null ? null : ['release_mbid' => $releaseMbid, 'image_id' => (string) $image['id']];
    }

    /** @return array{body:string,width:int,height:int} */
    public function download(string $releaseMbid, string $imageId): array
    {
        $releaseMbid = strtolower($releaseMbid);
        if (! Str::isUuid($releaseMbid) || preg_match('/\A[0-9]+\z/', $imageId) !== 1) {
            throw new RuntimeException('Invalid Cover Art Archive image request.');
        }
        [$response, $body] = $this->request($this->baseUrl()."/release/{$releaseMbid}/{$imageId}-1200.jpg", 'image/*', 20 * 1024 * 1024, 'Cover artwork exceeded the 20 MiB safety limit.');
        for ($redirects = 0; $response->redirect() && $redirects < 3; $redirects++) {
            $location = $response->header('Location');
            $this->assertArchiveUrl($location, $releaseMbid);
            [$response, $body] = $this->request($location, 'image/*', 20 * 1024 * 1024, 'Cover artwork exceeded the 20 MiB safety limit.');
        }
        if ($response->redirect()) {
            throw new RuntimeException('Cover Art Archive image exceeded the redirect limit.');
        }
        $response->throw();
        if ($body === '') {
            throw new RuntimeException('Cover artwork exceeded the 20 MiB safety limit.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Cover artwork was not a supported raster image.');
        }
        $dimensions = @getimagesizefromstring($body);
        if (! is_array($dimensions) || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > 2400 || $dimensions[1] > 2400
            || $dimensions[0] * $dimensions[1] > 6_000_000) {
            throw new RuntimeException('Cover artwork had unsafe dimensions.');
        }

        return ['body' => $body, 'width' => $dimensions[0], 'height' => $dimensions[1]];
    }

    /** @return array{Response,string} */
    private function request(string $url, string $accept, int $maximumBytes, string $exceptionMessage): array
    {
        $timeout = (int) config('services.cover_art_archive.timeout', 30);

        return retry(3, function () use ($accept, $exceptionMessage, $maximumBytes, $timeout, $url): array {
            $response = Http::accept($accept)
                ->withUserAgent((string) config('services.cover_art_archive.user_agent'))
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout])
                ->connectTimeout(10)
                ->timeout($timeout)
                ->get($url);
            if ($response->status() === 503) {
                $response->toPsrResponse()->getBody()->close();
                $response->throw();
            }
            $body = BoundedResponseBody::read(
                $response,
                $response->redirect() ? min($maximumBytes, 64 * 1024) : $maximumBytes,
                $exceptionMessage,
                $timeout,
            );

            return [$response, $body];
        }, 1000, fn (?Throwable $exception): bool => $exception instanceof RequestException
            && $exception->response->status() === 503);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.cover_art_archive.url'), '/');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('COVER_ART_ARCHIVE_URL must be a trusted HTTPS URL.');
        }

        return $url;
    }

    private function assertArchiveUrl(string $url, string $releaseMbid): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $trustedPath = str_starts_with($path, "/download/mbid-{$releaseMbid}/")
            || preg_match('#\A/[0-9]{1,3}/items/mbid-'.preg_quote($releaseMbid, '#').'/#', $path) === 1;
        if (($parts['scheme'] ?? null) !== 'https' || ($host !== 'archive.org' && ! str_ends_with($host, '.archive.org'))
            || ! $trustedPath
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new RuntimeException('Cover Art Archive redirected to an untrusted image host.');
        }
    }
}
