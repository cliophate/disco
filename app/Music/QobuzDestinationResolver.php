<?php

namespace App\Music;

use RuntimeException;

class QobuzDestinationResolver
{
    /** @return array{url:string,status:string,source:string} */
    public function resolve(string $kind, iterable $links, string $title, ?string $artist = null): array
    {
        if (! in_array($kind, ['artist', 'album'], true)) {
            throw new RuntimeException('Unsupported Qobuz destination kind.');
        }

        $candidates = collect($links)
            ->map(fn (mixed $link): ?array => is_array($link) && is_string($link['url'] ?? null)
                ? $this->exactCandidate($kind, $link['url'])
                : null)
            ->filter()
            ->sortBy('priority')
            ->values();
        if ($candidates->pluck('id')->unique()->count() === 1) {
            return [
                'url' => $candidates->first()['url'],
                'status' => 'exact',
                'source' => 'musicbrainz_url_relationship',
            ];
        }

        return [
            'url' => $this->searchUrl($title, $kind === 'album' ? $artist : null),
            'status' => 'search',
            'source' => 'catalog_search',
        ];
    }

    public function searchUrl(string $title, ?string $artist = null): string
    {
        $storefront = strtolower((string) config('services.qobuz.storefront', 'us-en'));
        if (! in_array($storefront, ['de-de', 'es-es', 'fr-fr', 'gb-en', 'ie-en', 'it-it', 'nl-nl', 'us-en'], true)) {
            $storefront = 'us-en';
        }
        $query = trim(($artist === null ? '' : $artist.' ').$title);

        return "https://www.qobuz.com/{$storefront}/search/?q=".rawurlencode($query);
    }

    /** @return array{id:string,url:string,priority:int}|null */
    private function exactCandidate(string $kind, string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $path = (string) ($parts['path'] ?? '');
        if ($host === 'open.qobuz.com'
            && preg_match('~\A/(artist|album)/([A-Za-z0-9]{1,64})/?\z~D', $path, $matches) === 1
            && $matches[1] === $kind
            && ($kind !== 'artist' || ctype_digit($matches[2]))) {
            return ['id' => $matches[2], 'url' => "https://open.qobuz.com/{$matches[1]}/{$matches[2]}", 'priority' => 0];
        }
        if (! in_array($host, ['qobuz.com', 'www.qobuz.com'], true)
            || preg_match('~\A/(?:[a-z]{2}-[a-z]{2}/)?(interpreter|album)/[^/]+/([A-Za-z0-9]{1,64})/?\z~D', $path, $matches) !== 1
            || ($kind === 'artist' ? $matches[1] !== 'interpreter' || ! ctype_digit($matches[2]) : $matches[1] !== 'album')) {
            return null;
        }

        return ['id' => $matches[2], 'url' => 'https://qobuz.com'.rtrim($path, '/'), 'priority' => 1];
    }
}
