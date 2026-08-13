<?php

namespace App\Music;

class ArtistLinkCurator
{
    private const PRIMARY_LIMIT = 4;

    /**
     * @param  iterable<array-key, mixed>  $links
     * @return array{primary: list<array{type: string, label: string, url: string}>, groups: list<array{label: string, links: list<array{type: string, label: string, url: string}>}>}
     */
    public function curate(iterable $links, ?string $musicBrainzId = null): array
    {
        $candidates = collect($links);

        if ($musicBrainzId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $musicBrainzId) === 1) {
            $candidates->push([
                'type' => 'musicbrainz',
                'url' => "https://musicbrainz.org/artist/{$musicBrainzId}",
            ]);
        }

        $curated = $candidates
            ->map(function (mixed $link): ?array {
                if (! is_array($link) || ! is_string($link['url'] ?? null)) {
                    return null;
                }

                $url = $this->canonicalize($link['url']);

                if ($url === null) {
                    return null;
                }

                return $this->classify($url, is_string($link['type'] ?? null) ? $link['type'] : '');
            })
            ->filter()
            ->sortBy(fn (array $link): string => sprintf('%03d:%s', $link['priority'], $link['url']))
            ->unique('url')
            ->unique('provider_key')
            ->values();

        $primary = $curated
            ->take(self::PRIMARY_LIMIT)
            ->map(fn (array $link): array => $this->publicLink($link))
            ->values()
            ->all();

        $remaining = $curated->skip(self::PRIMARY_LIMIT);
        $groups = collect([
            'official' => 'Official and stores',
            'catalog' => 'Catalogs and references',
            'listen' => 'Listen',
            'social' => 'Social',
            'other' => 'More links',
        ])->map(function (string $label, string $category) use ($remaining): ?array {
            $links = $remaining
                ->where('category', $category)
                ->map(fn (array $link): array => $this->publicLink($link))
                ->values()
                ->all();

            return $links === [] ? null : compact('label', 'links');
        })->filter()->values()->all();

        return compact('primary', 'groups');
    }

    private function canonicalize(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (
            ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            return null;
        }

        if (str_starts_with($host, 'www.') && $this->isKnownProviderHost(substr($host, 4))) {
            $host = substr($host, 4);
        }

        $path = $parts['path'] ?? '';
        $path = $path === '/' ? '' : ($this->isKnownProviderHost($host) ? rtrim($path, '/') : $path);
        $query = null;

        if (isset($parts['query'])) {
            $query = collect(explode('&', $parts['query']))
                ->reject(function (string $parameter): bool {
                    $key = strtolower(urldecode(explode('=', $parameter, 2)[0]));

                    return str_starts_with($key, 'utm_') || in_array($key, ['fbclid', 'gclid'], true);
                })
                ->implode('&');
        }

        $canonical = "https://{$host}{$path}";

        return $query === null || $query === '' ? $canonical : $canonical."?{$query}";
    }

    /** @return array{type: string, label: string, url: string, category: string, priority: int, provider_key: string} */
    private function classify(string $url, string $relation): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $relation = strtolower(trim($relation));
        $providerName = $this->providerName($host);
        $definition = match (true) {
            $this->hostMatches($host, 'musicbrainz.org') => ['musicbrainz', 'MusicBrainz', 'catalog', 20],
            $this->hostMatches($host, 'wikipedia.org') => ['wikipedia', 'Wikipedia', 'catalog', 30],
            $this->hostMatches($host, 'discogs.com') => ['discogs', 'Discogs', 'catalog', 40],
            $this->hostMatches($host, 'bandcamp.com') => ['bandcamp', 'Bandcamp', 'official', 50],
            $this->hostMatches($host, 'qobuz.com') => ['qobuz', 'Qobuz', 'official', 60],
            $this->hostMatches($host, 'spotify.com') => ['spotify', 'Spotify', 'listen', 70],
            $this->hostMatches($host, 'music.apple.com') => ['apple-music', 'Apple Music', 'listen', 80],
            $this->hostMatches($host, 'youtube.com'), $host === 'youtu.be' => ['youtube', 'YouTube', 'listen', 90],
            $this->hostMatches($host, 'soundcloud.com') => ['soundcloud', 'SoundCloud', 'listen', 100],
            $this->hostMatches($host, 'deezer.com') => ['deezer', 'Deezer', 'listen', 110],
            $this->hostMatches($host, 'tidal.com') => ['tidal', 'Tidal', 'listen', 120],
            $this->hostMatches($host, 'instagram.com') => ['instagram', 'Instagram', 'social', 200],
            $this->hostMatches($host, 'facebook.com') => ['facebook', 'Facebook', 'social', 210],
            $this->hostMatches($host, 'tiktok.com') => ['tiktok', 'TikTok', 'social', 220],
            $host === 'x.com', $this->hostMatches($host, 'twitter.com') => ['x', 'X', 'social', 230],
            in_array($relation, ['official homepage', 'official site', 'official'], true) => ['official', 'Official site', 'official', 10],
            str_contains($relation, 'streaming') => ['streaming', $providerName, 'listen', 130],
            str_contains($relation, 'download'), str_contains($relation, 'purchase') => ['store', $providerName, 'official', 140],
            str_contains($relation, 'social') => ['social', $providerName, 'social', 240],
            str_contains($relation, 'database'), str_contains($relation, 'discography') => ['reference', $providerName, 'catalog', 150],
            default => ['external', $providerName, 'other', 300],
        };

        return [
            'type' => $definition[0],
            'label' => $definition[1],
            'url' => $url,
            'category' => $definition[2],
            'priority' => $definition[3],
            'provider_key' => in_array($definition[0], ['official', 'streaming', 'store', 'social', 'reference', 'external'], true)
                ? strtolower($providerName)
                : $definition[0],
        ];
    }

    private function hostMatches(mixed $host, string $domain): bool
    {
        return is_string($host) && ($host === $domain || str_ends_with($host, ".{$domain}"));
    }

    private function isKnownProviderHost(string $host): bool
    {
        foreach ([
            'musicbrainz.org', 'wikipedia.org', 'discogs.com', 'bandcamp.com', 'qobuz.com',
            'spotify.com', 'music.apple.com', 'youtube.com', 'youtu.be', 'soundcloud.com',
            'deezer.com', 'tidal.com', 'instagram.com', 'facebook.com', 'tiktok.com',
            'x.com', 'twitter.com',
        ] as $domain) {
            if ($this->hostMatches($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    private function providerName(mixed $host): string
    {
        if (! is_string($host)) {
            return 'External link';
        }

        $name = explode('.', $this->providerDomain($host))[0];

        return ucfirst(str_replace(['-', '_'], ' ', $name));
    }

    private function providerDomain(mixed $host): string
    {
        if (! is_string($host)) {
            return 'external';
        }

        $parts = explode('.', preg_replace('/^www\./', '', $host) ?? $host);
        $length = count($parts);
        $labels = $length >= 2 ? 2 : 1;

        if ($length >= 3 && strlen($parts[$length - 1]) === 2 && in_array($parts[$length - 2], ['co', 'com', 'net', 'org'], true)) {
            $labels = 3;
        }

        return implode('.', array_slice($parts, -$labels));
    }

    /** @param array{type: string, label: string, url: string} $link */
    private function publicLink(array $link): array
    {
        return [
            'type' => $link['type'],
            'label' => $link['label'],
            'url' => $link['url'],
        ];
    }
}
