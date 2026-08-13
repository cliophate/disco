<?php

namespace App\Music\Plex;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class PlexClient
{
    private bool $artworkIdentityVerified = false;

    private bool $playbackIdentityVerified = false;

    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $token = null,
    ) {}

    /** @return array{machine_identifier:string, version:?string, name:string} */
    public function identity(): array
    {
        $root = $this->getXml('/identity', authenticated: false);
        $attributes = $this->attributes($root);
        $machineIdentifier = $attributes['machineIdentifier'] ?? '';

        if ($machineIdentifier === '') {
            throw new RuntimeException('Plex did not return a machine identifier.');
        }

        return [
            'machine_identifier' => $machineIdentifier,
            'version' => $attributes['version'] ?? null,
            'name' => $attributes['friendlyName'] ?? 'Plex',
        ];
    }

    /** @return array{key:string, uuid:?string, title:string, type:string, agent:?string, scanner:?string} */
    public function musicLibrary(string $title): array
    {
        $root = $this->getXml('/library/sections');

        foreach ($root->Directory as $directory) {
            $attributes = $this->attributes($directory);

            if (($attributes['title'] ?? null) === $title && ($attributes['type'] ?? null) === 'artist') {
                return [
                    'key' => $attributes['key'],
                    'uuid' => $attributes['uuid'] ?? null,
                    'title' => $attributes['title'],
                    'type' => $attributes['type'],
                    'agent' => $attributes['agent'] ?? null,
                    'scanner' => $attributes['scanner'] ?? null,
                ];
            }
        }

        throw new RuntimeException("Plex music library [{$title}] was not found.");
    }

    /**
     * @return list<array{attributes:array<string,string>, guids:list<string>, media_parts:list<array<string,?string>>}>
     */
    public function libraryItems(string $sectionKey, int $type): array
    {
        $items = [];
        $offset = 0;
        $total = null;
        $pageSize = 500;

        do {
            $root = $this->getXml("/library/sections/{$sectionKey}/all", [
                'type' => $type,
                'includeGuids' => 1,
                'X-Plex-Container-Start' => $offset,
                'X-Plex-Container-Size' => $pageSize,
            ]);
            $rootAttributes = $this->attributes($root);
            $total ??= (int) ($rootAttributes['totalSize'] ?? $rootAttributes['size'] ?? 0);
            $pageCount = 0;

            foreach ($root->children() as $node) {
                $attributes = $this->attributes($node);

                if (! isset($attributes['ratingKey'], $attributes['title'])) {
                    continue;
                }

                $guids = [];
                foreach ($node->Guid as $guid) {
                    $id = (string) $guid['id'];
                    if ($id !== '') {
                        $guids[] = $id;
                    }
                }

                if (isset($attributes['guid']) && str_starts_with($attributes['guid'], 'mbid://')) {
                    $guids[] = $attributes['guid'];
                }

                $items[] = [
                    'attributes' => $attributes,
                    'guids' => array_values(array_unique($guids)),
                    'media_parts' => $this->mediaParts($node),
                ];
                $pageCount++;
            }

            if ($pageCount === 0 && $offset < $total) {
                throw new RuntimeException('Plex returned an incomplete paginated library response.');
            }

            $offset += $pageCount;
        } while ($offset < $total);

        return $items;
    }

    /** @return list<array{rating_key:string,parent_rating_key:string,grandparent_rating_key:?string,state:string}> */
    public function activeSessions(): array
    {
        $root = $this->getXml('/status/sessions');
        $sessions = [];
        foreach ($root->Track as $track) {
            $attributes = $this->attributes($track);
            $player = $track->Player->count() > 0 ? $this->attributes($track->Player[0]) : [];
            $state = $player['state'] ?? '';
            if (! isset($attributes['ratingKey'], $attributes['parentRatingKey']) || ! in_array($state, ['playing', 'paused', 'buffering'], true)) {
                continue;
            }
            $sessions[] = [
                'rating_key' => $attributes['ratingKey'],
                'parent_rating_key' => $attributes['parentRatingKey'],
                'grandparent_rating_key' => $attributes['grandparentRatingKey'] ?? null,
                'state' => $state,
            ];
        }

        return $sessions;
    }

    /** @return array{body:string,mime_type:string,width:int,height:int,extension:string} */
    public function artwork(string $path, string $expectedRatingKey, ?string $expectedParentRatingKey = null): array
    {
        if (preg_match('#\A/library/metadata/([1-9][0-9]{0,18})/thumb/([1-9][0-9]{0,18})\z#D', $path, $matches) !== 1
            || (! hash_equals($expectedRatingKey, $matches[1])
                && ($expectedParentRatingKey === null || ! hash_equals($expectedParentRatingKey, $matches[1])))) {
            throw new RuntimeException('Plex returned an invalid artwork path.');
        }

        $this->verifyArtworkIdentity();

        $temporaryPath = tempnam(sys_get_temp_dir(), 'disco-artwork-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not allocate temporary artwork storage.');
        }
        try {
            $response = $this->request()
                ->accept('image/jpeg, image/png, image/webp')
                ->withHeaders(['X-Plex-Token' => $this->resolvedToken()])
                ->withOptions([
                    'sink' => $temporaryPath,
                    'progress' => function ($downloadTotal, $downloadedBytes): void {
                        if ($downloadTotal > 20 * 1024 * 1024 || $downloadedBytes > 20 * 1024 * 1024) {
                            throw new RuntimeException('Plex artwork exceeded the 20 MiB safety limit.');
                        }
                    },
                ])
                ->get($path)
                ->throw();
            $contentLength = (int) $response->header('Content-Length', '0');
            if ($contentLength > 20 * 1024 * 1024 || $response->header('Content-Encoding') !== '') {
                throw new RuntimeException('Plex artwork exceeded the transport safety policy.');
            }
            $body = filesize($temporaryPath) > 0 ? file_get_contents($temporaryPath) : $response->body();
        } finally {
            @unlink($temporaryPath);
        }
        if (! is_string($body)) {
            throw new RuntimeException('Plex artwork could not be read.');
        }
        if ($body === '' || strlen($body) > 20 * 1024 * 1024) {
            throw new RuntimeException('Plex artwork exceeded the 20 MiB safety limit.');
        }

        $headerMime = strtolower(trim(explode(';', $response->header('Content-Type', ''))[0]));
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $allowed = [
            'image/jpeg' => ['type' => IMAGETYPE_JPEG, 'extension' => 'jpg'],
            'image/png' => ['type' => IMAGETYPE_PNG, 'extension' => 'png'],
            'image/webp' => ['type' => IMAGETYPE_WEBP, 'extension' => 'webp'],
        ];
        if (! isset($allowed[$headerMime], $allowed[$detectedMime])) {
            throw new RuntimeException('Plex artwork returned an unsupported content type.');
        }

        $dimensions = @getimagesizefromstring($body);
        if ($dimensions === false || $dimensions[2] !== $allowed[$detectedMime]['type']) {
            throw new RuntimeException('Plex artwork could not be decoded as the declared image type.');
        }
        [$width, $height] = $dimensions;
        if ($width < 1 || $height < 1 || $width > 5000 || $height > 5000 || $width * $height > 25_000_000) {
            throw new RuntimeException('Plex artwork dimensions exceeded the safety limit.');
        }

        return [
            'body' => $body,
            'mime_type' => $detectedMime,
            'width' => $width,
            'height' => $height,
            'extension' => $allowed[$detectedMime]['extension'],
        ];
    }

    public function originalPart(string $path, string $expectedPartId, ?string $range, string $mimeType): Response
    {
        if (preg_match('#\A/library/parts/([1-9][0-9]{0,18})/[1-9][0-9]{0,18}/file(?:\.[A-Za-z0-9]{1,10})?\z#D', $path, $matches) !== 1
            || ! hash_equals($expectedPartId, $matches[1])) {
            throw new RuntimeException('Plex returned an invalid media part path.');
        }
        $this->verifyPlaybackIdentity();

        $headers = ['X-Plex-Token' => $this->resolvedToken()];
        if ($range !== null) {
            $headers['Range'] = $range;
        }

        return $this->request(retry: false)
            ->accept($mimeType)
            ->withHeaders($headers)
            ->withOptions(['stream' => true, 'decode_content' => false, 'read_timeout' => 30])
            ->timeout(30)
            ->get($path);
    }

    public function playbackTimeline(string $ratingKey, string $state, int $positionMs, int $durationMs, string $clientIdentifier): void
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $ratingKey) !== 1
            || ! in_array($state, ['playing', 'paused', 'stopped'], true)
            || $positionMs < 0 || $durationMs < 1 || $positionMs > $durationMs
            || preg_match('/\Adisco-[a-f0-9]{32}\z/D', $clientIdentifier) !== 1) {
            throw new RuntimeException('Invalid Plex playback timeline state.');
        }
        $this->verifyPlaybackIdentity();
        $this->playbackRequest($clientIdentifier)->get('/:/timeline', [
            'ratingKey' => $ratingKey,
            'key' => "/library/metadata/{$ratingKey}",
            'state' => $state,
            'time' => $positionMs,
            'duration' => $durationMs,
        ])->throw();
    }

    public function scrobble(string $ratingKey, string $clientIdentifier): void
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $ratingKey) !== 1
            || preg_match('/\Adisco-[a-f0-9]{32}\z/D', $clientIdentifier) !== 1) {
            throw new RuntimeException('Invalid Plex scrobble target.');
        }
        $this->verifyPlaybackIdentity();
        $this->playbackRequest($clientIdentifier)->get('/:/scrobble', [
            'key' => $ratingKey,
            'identifier' => 'com.plexapp.plugins.library',
        ])->throw();
    }

    private function verifyArtworkIdentity(): void
    {
        if ($this->artworkIdentityVerified) {
            return;
        }
        $expected = (string) config('services.plex.expected_machine_identifier');
        if ($expected === '') {
            throw new RuntimeException('PLEX_EXPECTED_MACHINE_IDENTIFIER is required for artwork ingestion.');
        }
        $actual = $this->identity()['machine_identifier'];
        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException('Plex artwork origin machine identifier mismatch.');
        }
        $this->artworkIdentityVerified = true;
    }

    private function verifyPlaybackIdentity(): void
    {
        if ($this->playbackIdentityVerified) {
            return;
        }
        $expected = (string) config('services.plex.expected_machine_identifier');
        if ($expected === '') {
            throw new RuntimeException('PLEX_EXPECTED_MACHINE_IDENTIFIER is required for playback.');
        }
        $verificationKey = 'disco:plex-playback-origin:'.hash('sha256', $this->resolvedBaseUrl()."\0".$expected);
        if (Cache::get($verificationKey) === true) {
            $this->playbackIdentityVerified = true;

            return;
        }
        if (! hash_equals($expected, $this->identity()['machine_identifier'])) {
            throw new RuntimeException('Plex playback origin machine identifier mismatch.');
        }
        Cache::put($verificationKey, true, 300);
        $this->playbackIdentityVerified = true;
    }

    /** @param array<string, int|string> $query */
    private function getXml(string $path, array $query = [], bool $authenticated = true): SimpleXMLElement
    {
        $request = $this->request();
        if ($authenticated) {
            $request = $request->withHeaders(['X-Plex-Token' => $this->resolvedToken()]);
        }

        $response = $request->get($path, $query)->throw();
        $body = $response->body();
        if (strlen($body) > 10 * 1024 * 1024) {
            throw new RuntimeException('Plex XML response exceeded the 10 MiB safety limit.');
        }
        $contentType = strtolower($response->header('Content-Type', ''));
        if ($contentType !== '' && ! str_contains($contentType, 'xml')) {
            throw new RuntimeException('Plex returned an unexpected content type.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Plex returned invalid XML.');
        }

        return $xml;
    }

    private function request(bool $retry = true): PendingRequest
    {
        $baseUrl = $this->resolvedBaseUrl();
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? null;

        if ($baseUrl === '' || ! in_array($scheme, ['https', 'http'], true) || ! isset($parts['host'])) {
            throw new RuntimeException('PLEX_URL must be a valid HTTP or HTTPS URL.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
            throw new RuntimeException('PLEX_URL must be an origin without credentials, path, query, or fragment.');
        }

        if ($scheme !== 'https' && ! config('services.plex.allow_insecure_http')) {
            throw new RuntimeException('Plex HTTP is disabled. Configure a verified HTTPS endpoint.');
        }

        $request = Http::baseUrl($baseUrl)
            ->accept('application/xml')
            ->withUserAgent('Disco/0.1 (https://github.com/cliophate/disco)')
            ->withOptions(['allow_redirects' => false])
            ->timeout((int) config('services.plex.timeout', 30))
            ->connectTimeout(10);

        return $retry ? $request->retry(2, 250) : $request;
    }

    private function playbackRequest(string $clientIdentifier): PendingRequest
    {
        return $this->request(retry: false)->timeout(10)->connectTimeout(5)->withHeaders([
            'X-Plex-Token' => $this->resolvedToken(),
            'X-Plex-Product' => 'Disco',
            'X-Plex-Version' => '0.1',
            'X-Plex-Client-Identifier' => $clientIdentifier,
            'X-Plex-Platform' => 'Web',
            'X-Plex-Device' => 'Disco',
            'X-Plex-Device-Name' => 'Disco',
        ]);
    }

    private function resolvedBaseUrl(): string
    {
        return rtrim($this->baseUrl ?? (string) config('services.plex.url'), '/');
    }

    private function resolvedToken(): string
    {
        $token = $this->token ?? (string) config('services.plex.token');

        if ($token === '') {
            throw new RuntimeException('PLEX_TOKEN is not configured.');
        }

        return $token;
    }

    /** @return array<string, string> */
    private function attributes(SimpleXMLElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes() as $key => $value) {
            $attributes[(string) $key] = (string) $value;
        }

        return $attributes;
    }

    /** @return list<array<string, ?string>> */
    private function mediaParts(SimpleXMLElement $node): array
    {
        $parts = [];
        foreach ($node->Media as $media) {
            $mediaAttributes = $this->attributes($media);
            foreach ($media->Part as $part) {
                $partAttributes = $this->attributes($part);
                $audio = [];
                foreach ($part->Stream as $stream) {
                    $streamAttributes = $this->attributes($stream);
                    if (($streamAttributes['streamType'] ?? null) === '2') {
                        $audio = $streamAttributes;
                        break;
                    }
                }

                $parts[] = [
                    'media_id' => $mediaAttributes['id'] ?? null,
                    'part_id' => $partAttributes['id'] ?? null,
                    'part_key' => $partAttributes['key'] ?? null,
                    'container' => $partAttributes['container'] ?? $mediaAttributes['container'] ?? null,
                    'audio_codec' => $audio['codec'] ?? $mediaAttributes['audioCodec'] ?? null,
                    'channels' => $audio['channels'] ?? $mediaAttributes['audioChannels'] ?? null,
                    'bit_depth' => $audio['bitDepth'] ?? $mediaAttributes['bitDepth'] ?? null,
                    'sample_rate_hz' => $audio['samplingRate'] ?? $audio['sampleRate'] ?? $mediaAttributes['audioSampleRate'] ?? null,
                    'bitrate_kbps' => $audio['bitrate'] ?? $mediaAttributes['bitrate'] ?? null,
                    'size_bytes' => $partAttributes['size'] ?? null,
                    'duration_ms' => $partAttributes['duration'] ?? $mediaAttributes['duration'] ?? null,
                ];
            }
        }

        return $parts;
    }
}
