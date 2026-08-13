<?php

namespace Tests\Unit;

use App\Music\ArtistLinkCurator;
use PHPUnit\Framework\TestCase;

class ArtistLinkCuratorTest extends TestCase
{
    public function test_it_canonicalizes_deduplicates_and_prioritizes_artist_links(): void
    {
        $links = (new ArtistLinkCurator)->curate([
            ['type' => 'official homepage', 'url' => 'https://www.fixture.test/'],
            ['type' => 'streaming music', 'url' => 'https://open.spotify.com/artist/123?utm_source=fixture'],
            ['type' => 'streaming music', 'url' => 'https://open.spotify.com/artist/123/'],
            ['type' => 'streaming music', 'url' => 'https://open.spotify.com/artist/different'],
            ['type' => 'social network', 'url' => 'https://instagram.com/fixture/'],
            ['type' => 'other databases', 'url' => 'https://www.discogs.com/artist/123-Fixture'],
            ['type' => 'other databases', 'url' => 'https://en.wikipedia.org/wiki/Fixture'],
            ['type' => 'purchase for download', 'url' => 'https://fixture.bandcamp.com/'],
            ['type' => 'purchase for download', 'url' => 'https://downloads.record-label.test/fixture'],
            ['type' => 'purchase for download', 'url' => 'https://www.record-label.test/artists/fixture'],
            ['type' => 'purchase for download', 'url' => 'https://another-store.test/fixture'],
            ['type' => 'purchase for download', 'url' => 'https://music.amazon.com/albums/fixture'],
            ['type' => 'purchase for download', 'url' => 'https://music.amazon.co.uk/albums/fixture'],
        ], '11111111-1111-4111-8111-111111111111');

        $this->assertSame(
            ['Official site', 'MusicBrainz', 'Wikipedia', 'Discogs'],
            array_column($links['primary'], 'label'),
        );
        $this->assertSame('https://www.fixture.test', $links['primary'][0]['url']);
        $this->assertSame('https://musicbrainz.org/artist/11111111-1111-4111-8111-111111111111', $links['primary'][1]['url']);
        $this->assertSame(['Official and stores', 'Listen', 'Social'], array_column($links['groups'], 'label'));
        $this->assertSame(['Bandcamp', 'Another store', 'Record label', 'Amazon'], array_column($links['groups'][0]['links'], 'label'));
        $this->assertCount(1, $links['groups'][1]['links']);
        $this->assertSame('https://open.spotify.com/artist/123', $links['groups'][1]['links'][0]['url']);
    }

    public function test_it_rejects_unsafe_and_malformed_urls(): void
    {
        $links = (new ArtistLinkCurator)->curate([
            ['type' => 'official homepage', 'url' => 'http://fixture.test'],
            ['type' => 'official homepage', 'url' => 'https://user:secret@fixture.test'],
            ['type' => 'official homepage', 'url' => 'https://fixture.test:8443'],
            ['type' => 'official homepage', 'url' => 'https://localhost'],
            ['type' => 'official homepage', 'url' => 'https://[::1]'],
            ['type' => 'other databases', 'url' => 'https://attacker.example\\.musicbrainz.org/artist/123'],
            ['type' => 'official homepage', 'url' => 'not a url'],
        ]);

        $this->assertSame(['primary' => [], 'groups' => []], $links);
    }
}
