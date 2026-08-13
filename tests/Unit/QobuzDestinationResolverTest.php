<?php

namespace Tests\Unit;

use App\Music\QobuzDestinationResolver;
use Tests\TestCase;

class QobuzDestinationResolverTest extends TestCase
{
    public function test_it_accepts_one_exact_artist_identity_across_duplicate_musicbrainz_links(): void
    {
        $destination = app(QobuzDestinationResolver::class)->resolve('artist', [
            ['url' => 'https://www.qobuz.com/us-en/interpreter/jon-hopkins/384387'],
            ['url' => 'https://open.qobuz.com/artist/384387'],
            ['url' => 'https://www.qobuz.com/us-en/interpreter/jon-hopkins/download-streaming-albums'],
        ], 'Jon Hopkins');

        $this->assertSame('exact', $destination['status']);
        $this->assertSame('https://open.qobuz.com/artist/384387', $destination['url']);
        $this->assertSame('musicbrainz_url_relationship', $destination['source']);
    }

    public function test_it_falls_back_when_exact_artist_links_conflict(): void
    {
        config()->set('services.qobuz.storefront', 'gb-en');

        $destination = app(QobuzDestinationResolver::class)->resolve('artist', [
            ['url' => 'https://open.qobuz.com/artist/508828'],
            ['url' => 'https://www.qobuz.com/gb-en/interpreter/donda/1826621'],
        ], 'Ye');

        $this->assertSame('search', $destination['status']);
        $this->assertSame('https://www.qobuz.com/gb-en/search/?q=Ye', $destination['url']);
    }

    public function test_it_accepts_an_exact_album_and_rejects_generic_or_unsafe_links(): void
    {
        $resolver = app(QobuzDestinationResolver::class);
        $exact = $resolver->resolve('album', [
            ['url' => 'https://www.qobuz.com/ie-en/album/fixture-album/0886445885030'],
            ['url' => 'https://open.qobuz.com/album/0886445885030'],
            ['url' => 'https://open.qobuz.com/album/another?token=unsafe'],
        ], 'Fixture Album', 'Fixture Artist');

        $this->assertSame('exact', $exact['status']);
        $this->assertSame('https://open.qobuz.com/album/0886445885030', $exact['url']);

        $fallback = $resolver->resolve('album', [
            ['url' => 'https://www.qobuz.com/ie-en/interpreter/fixture/download-streaming-albums'],
            ['url' => 'https://user@open.qobuz.com/album/unsafe'],
        ], 'Fixture Album', 'Fixture Artist');
        $this->assertSame('search', $fallback['status']);
        $this->assertStringContainsString('Fixture%20Artist%20Fixture%20Album', $fallback['url']);
    }
}
