<?php

namespace Tests\Feature;

use App\Music\Descriptions\TheAudioDbClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TheAudioDbClientTest extends TestCase
{
    public function test_artist_lookup_uses_the_exact_musicbrainz_identity(): void
    {
        config()->set('services.theaudiodb.rate_interval_ms', 0);
        $mbid = '11111111-1111-4111-8111-111111111111';
        Http::fake([
            'www.theaudiodb.com/api/v1/json/123/artist-mb.php*' => Http::response(['artists' => [[
                'idArtist' => '12345',
                'strMusicBrainzID' => strtoupper($mbid),
                'strBiography' => 'An artist biography.',
            ]]], 200, ['Content-Type' => 'application/json']),
        ]);

        $artist = app(TheAudioDbClient::class)->artist($mbid);

        $this->assertSame('12345', $artist['idArtist']);
        $this->assertSame('An artist biography.', $artist['strBiography']);
        Http::assertSent(fn ($request): bool => $request->url() === "https://www.theaudiodb.com/api/v1/json/123/artist-mb.php?i={$mbid}");
    }

    public function test_artist_lookup_rejects_another_musicbrainz_identity(): void
    {
        config()->set('services.theaudiodb.rate_interval_ms', 0);
        Http::fake([
            'www.theaudiodb.com/api/v1/json/123/artist-mb.php*' => Http::response(['artists' => [[
                'idArtist' => '12345',
                'strMusicBrainzID' => '22222222-2222-4222-8222-222222222222',
            ]]], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another artist identity');
        app(TheAudioDbClient::class)->artist('11111111-1111-4111-8111-111111111111');
    }

    public function test_artist_lookup_rejects_a_malformed_payload(): void
    {
        config()->set('services.theaudiodb.rate_interval_ms', 0);
        Http::fake([
            'www.theaudiodb.com/api/v1/json/123/artist-mb.php*' => Http::response(['artists' => 'invalid'], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid artist response');
        app(TheAudioDbClient::class)->artist('11111111-1111-4111-8111-111111111111');
    }
}
