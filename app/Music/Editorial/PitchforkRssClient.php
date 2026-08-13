<?php

namespace App\Music\Editorial;

use App\Music\Http\BoundedResponseBody;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PitchforkRssClient
{
    /** @return array{status:int,items:list<array<string,mixed>>} */
    public function fetch(string $feedUrl): array
    {
        $allowed = array_values(config('discovery.editorial.pitchfork.feeds', []));
        if (! in_array($feedUrl, $allowed, true) || parse_url($feedUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Pitchfork RSS URL is not an approved HTTPS feed.');
        }

        $response = Http::accept('application/rss+xml, application/xml;q=0.9')
            ->connectTimeout((int) config('discovery.editorial.pitchfork.connect_timeout_seconds', 5))
            ->timeout((int) config('discovery.editorial.pitchfork.timeout_seconds', 15))
            ->get($feedUrl)
            ->throw();
        $xml = BoundedResponseBody::read(
            $response,
            (int) config('discovery.editorial.pitchfork.maximum_bytes', 1_000_000),
            'Pitchfork RSS response exceeded the configured byte limit.',
            (int) config('discovery.editorial.pitchfork.timeout_seconds', 15),
        );

        return ['status' => $response->status(), 'items' => $this->parse($xml)];
    }

    /** @return list<array<string,mixed>> */
    public function parse(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('Pitchfork RSS must not contain a document type or external entity.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('Pitchfork RSS was malformed.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $items = [];
        $seen = [];
        $limit = (int) config('discovery.editorial.pitchfork.maximum_items_per_feed', 50);
        foreach ($xpath->query('//*[local-name()="item"]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $headline = $this->text($xpath, $node, 'title');
            $canonical = $this->canonicalUrl($this->text($xpath, $node, 'link'));
            $guid = $this->text($xpath, $node, 'guid') ?: $canonical;
            if ($headline === null || $canonical === null || isset($seen[$guid]) || isset($seen[$canonical])) {
                continue;
            }
            $published = $this->date($this->text($xpath, $node, 'pubDate'));
            if ($published === null) {
                continue;
            }
            $thumbnail = $xpath->query('.//*[local-name()="thumbnail"][@url][1]', $node)?->item(0);
            $imageUrl = $thumbnail instanceof DOMElement ? $this->imageUrl($thumbnail->getAttribute('url')) : null;
            $seen[$guid] = $seen[$canonical] = true;
            $items[] = [
                'guid' => mb_substr($guid, 0, 255),
                'canonical_url' => $canonical,
                'headline' => mb_substr($headline, 0, 255),
                'excerpt' => $this->excerpt($this->text($xpath, $node, 'description')),
                'author' => $this->bounded($this->text($xpath, $node, 'creator') ?? $this->text($xpath, $node, 'author'), 255),
                'publisher' => $this->bounded($this->text($xpath, $node, 'publisher'), 80) ?? 'Condé Nast',
                'category' => $this->bounded($this->text($xpath, $node, 'category'), 80),
                'image_url' => $imageUrl,
                'image_width' => $imageUrl === null || ! $thumbnail instanceof DOMElement ? null : $this->dimension($thumbnail->getAttribute('width')),
                'image_height' => $imageUrl === null || ! $thumbnail instanceof DOMElement ? null : $this->dimension($thumbnail->getAttribute('height')),
                'published_at' => $published,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        if ($items === []) {
            throw new RuntimeException('Pitchfork RSS contained no eligible linked items.');
        }

        return $items;
    }

    private function text(DOMXPath $xpath, DOMElement $node, string $localName): ?string
    {
        $value = trim((string) $xpath->evaluate("string(.//*[local-name()='{$localName}'][1])", $node));

        return $value === '' ? null : $value;
    }

    private function canonicalUrl(?string $url): ?string
    {
        if ($url === null || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'pitchfork.com' || $host === 'www.pitchfork.com' ? mb_substr($url, 0, 2048) : null;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function excerpt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $plain = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $this->bounded(is_string($plain) ? trim($plain) : null, 600);
    }

    private function bounded(?string $value, int $limit): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function imageUrl(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_HOST)) === 'media.pitchfork.com' ? mb_substr($url, 0, 2048) : null;
    }

    private function dimension(?string $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? min(20_000, max(1, (int) $value)) : null;
    }
}
