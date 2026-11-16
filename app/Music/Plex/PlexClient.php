<?php

namespace App\Music\Plex;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class PlexClient
{
    private bool $artworkIdentityVerified = false;

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
     * @return list<array{attributes:array<string,string>, guids:list<string>}>
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
}
