<?php

namespace App\Operations;

class ManualOperationCatalog
{
    /** @var array<string, array{command: string, arguments: array<string, int>}> */
    private const OPERATIONS = [
        'plex.sync' => ['command' => 'disco:plex-sync', 'arguments' => []],
        'listenbrainz.import' => ['command' => 'disco:listenbrainz-sync', 'arguments' => ['--max-pages' => 10]],
        'listenbrainz.recommendations' => ['command' => 'disco:listenbrainz-recommendations', 'arguments' => ['--count' => 250, '--limit' => 25]],
        'musicbrainz.enrich' => ['command' => 'disco:musicbrainz-enrich', 'arguments' => ['--limit' => 50]],
        'discogs.enrich' => ['command' => 'disco:discogs-enrich', 'arguments' => ['--limit' => 20]],
        'artwork.discographies' => ['command' => 'disco:discography-artwork', 'arguments' => ['--limit' => 15]],
        'catalog.enrich' => ['command' => 'disco:catalog-enrich', 'arguments' => ['--limit' => 50]],
        'upcoming.refresh' => ['command' => 'disco:upcoming-releases', 'arguments' => []],
        'notifications.deliver' => ['command' => 'disco:upcoming-notification-delivery', 'arguments' => ['--limit' => 50]],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::OPERATIONS);
    }

    /** @return array{command: string, arguments: array<string, int>}|null */
    public function find(string $operationKey): ?array
    {
        return self::OPERATIONS[$operationKey] ?? null;
    }

    public function concurrencyKey(string $ownerId, string $operationKey): string
    {
        return $ownerId.':'.$operationKey;
    }
}
