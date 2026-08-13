<?php

namespace Tests\Feature;

use App\Music\Editorial\EditorialDiscoveryService;
use App\Music\Editorial\PitchforkRssClient;
use App\Music\Editorial\PitchforkRssRefresher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PitchforkRssTest extends TestCase
{
    public function test_parser_keeps_only_approved_feed_fields_and_rejects_unsafe_xml(): void
    {
        config()->set('discovery.editorial.pitchfork.maximum_items_per_feed', 10);
        $items = app(PitchforkRssClient::class)->parse($this->feed('review-1', 'A &amp; B', '<b>Feed</b> excerpt'));

        $this->assertCount(1, $items);
        $this->assertSame('A & B', $items[0]['headline']);
        $this->assertSame('Feed excerpt', $items[0]['excerpt']);
        $this->assertSame('https://media.pitchfork.com/photos/fixture/master/pass/cover.jpg', $items[0]['image_url']);
        $this->assertSame(1200, $items[0]['image_width']);
        $this->assertArrayNotHasKey('body', $items[0]);

        $this->expectException(RuntimeException::class);
        app(PitchforkRssClient::class)->parse('<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss><channel><item>&xxe;</item></channel></rss>');
    }

    public function test_refresh_is_bounded_idempotent_immutable_and_provider_free_at_render_time(): void
    {
        $this->preparePostgres();
        config()->set('discovery.editorial.pitchfork.enabled', true);
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), 'album-reviews')
            ? $this->feed('review-1', 'Fixture Review', 'Review excerpt')
            : $this->feed('news-1', 'Fixture News', 'News excerpt', '/story/fixture-news/'), 200, ['Content-Type' => 'application/rss+xml']));

        $first = app(PitchforkRssRefresher::class)->refresh();
        $this->assertSame(['feeds' => 2, 'created' => 2, 'refreshed' => 0, 'pruned' => 0], $first);
        $this->assertDatabaseCount('discovery.editorial_items', 2);
        $this->assertDatabaseCount('source.snapshots', 2);
        $this->assertSame('Fixture Review', app(EditorialDiscoveryService::class)->current()[0]['headline']);
        config()->set('discovery.editorial.pitchfork.enabled', false);
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), 'album-reviews')
            ? $this->feed('review-1', 'Changed headline', 'Changed excerpt')
            : $this->feed('news-1', 'Changed news', 'Changed excerpt', '/story/fixture-news/'), 200));
        $second = app(PitchforkRssRefresher::class)->refresh();
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['refreshed']);
        $this->assertDatabaseHas('discovery.editorial_items', ['guid' => 'review-1', 'headline' => 'Fixture Review']);

        $providerCalls = count(Http::recorded());
        app(EditorialDiscoveryService::class)->current();
        $this->assertSame($providerCalls, count(Http::recorded()));
        DB::table('discovery.editorial_items')->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], app(EditorialDiscoveryService::class)->current());
    }

    private function feed(string $guid, string $title, string $description, string $path = '/reviews/albums/fixture/'): string
    {
        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <rss xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/" version="2.0"><channel><item>
            <title>{$title}</title><link>https://pitchfork.com{$path}</link><guid>{$guid}</guid>
            <pubDate>Fri, 24 Jul 2026 04:03:00 +0000</pubDate><description>{$description}</description>
            <category>Reviews / Albums</category><dc:creator>Fixture Writer</dc:creator><dc:publisher>Condé Nast</dc:publisher>
            <media:thumbnail url="https://media.pitchfork.com/photos/fixture/master/pass/cover.jpg" width="1200" height="1200"/>
            </item></channel></rss>
            XML;
    }

    private function preparePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration test; use compose.test.yaml.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than the dedicated disco_test database.');
        }
        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
