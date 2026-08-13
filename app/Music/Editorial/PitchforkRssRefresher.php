<?php

namespace App\Music\Editorial;

use App\Models\EditorialItem;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PitchforkRssRefresher
{
    public function __construct(private readonly PitchforkRssClient $client) {}

    /** @return array{feeds:int,created:int,refreshed:int,pruned:int} */
    public function refresh(bool $force = false): array
    {
        $enabled = (bool) config('discovery.editorial.pitchfork.enabled')
            || SourceProvider::query()->where('slug', 'pitchfork')->where('enabled', true)->exists();
        if (! $enabled && ! $force) {
            throw new RuntimeException('Pitchfork RSS is disabled. Enable PITCHFORK_RSS_ENABLED for an approved private noncommercial deployment.');
        }
        $lock = Cache::lock('disco:pitchfork-rss', 300);
        if (! $lock->get()) {
            throw new RuntimeException('Another Pitchfork RSS refresh is already running.');
        }

        try {
            $feeds = [];
            foreach (config('discovery.editorial.pitchfork.feeds', []) as $name => $url) {
                $feeds[] = ['name' => (string) $name, 'url' => (string) $url, ...$this->client->fetch((string) $url)];
            }

            return DB::transaction(function () use ($feeds): array {
                $now = now();
                $expires = $now->copy()->addHours((int) config('discovery.editorial.pitchfork.expiry_hours', 72));
                $provider = SourceProvider::query()->firstOrCreate(
                    ['slug' => 'pitchfork'],
                    ['display_name' => 'Pitchfork', 'enabled' => true, 'policy' => ['storage' => 'official_rss_fields', 'connector' => 'read_only', 'article_scraping' => false]],
                );
                $provider->update(['enabled' => true]);
                $created = 0;
                $refreshed = 0;
                foreach ($feeds as $feed) {
                    $object = SourceObject::query()->firstOrCreate(
                        ['provider_id' => $provider->id, 'object_type' => 'official_rss', 'external_id' => $feed['name']],
                        ['canonical_url' => $feed['url'], 'first_seen_at' => $now, 'last_seen_at' => $now],
                    );
                    $object->update(['canonical_url' => $feed['url'], 'last_seen_at' => $now]);
                    $payload = ['feed' => $feed['name'], 'items' => $feed['items']];
                    $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $snapshot = SourceSnapshot::query()->firstOrCreate(
                        ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', $encoded)],
                        ['retrieved_at' => $now, 'http_status' => $feed['status'], 'payload' => $payload, 'parser_version' => 'pitchfork-rss-v1', 'expires_at' => $expires],
                    );
                    $snapshot->update(['retrieved_at' => $now, 'http_status' => $feed['status'], 'expires_at' => $expires]);

                    foreach ($feed['items'] as $item) {
                        $record = EditorialItem::query()->where('source', 'pitchfork')
                            ->where(fn ($query) => $query->where('guid', $item['guid'])->orWhere('canonical_url', $item['canonical_url']))
                            ->first();
                        if ($record === null) {
                            EditorialItem::query()->create([
                                'source_snapshot_id' => $snapshot->id,
                                'source' => 'pitchfork',
                                'feed_url' => $feed['url'],
                                ...$item,
                                'retrieved_at' => $now,
                                'expires_at' => $expires,
                            ]);
                            $created++;
                        } else {
                            $record->update(['source_snapshot_id' => $snapshot->id, 'retrieved_at' => $now, 'expires_at' => $expires]);
                            $refreshed++;
                        }
                    }
                }
                $pruned = EditorialItem::query()
                    ->where('published_at', '<', $now->copy()->subDays((int) config('discovery.editorial.pitchfork.retention_days', 30)))
                    ->delete();

                return ['feeds' => count($feeds), 'created' => $created, 'refreshed' => $refreshed, 'pruned' => $pruned];
            });
        } finally {
            $lock->release();
        }
    }
}
