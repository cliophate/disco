<?php

namespace Tests\Feature;

use App\Music\Discogs\DiscogsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DiscogsClientTest extends TestCase
{
    public function test_catalog_reads_use_header_authentication_and_a_unique_user_agent(): void
    {
        config()->set('services.discogs', [
            'url' => 'https://api.discogs.test',
            'token' => 'private-token',
            'timeout' => 5,
            'rate_interval_ms' => 0,
            'user_agent' => 'DiscoTest/1.0 +https://example.test',
        ]);
        Http::fake(['https://api.discogs.test/releases/42' => Http::response(['id' => 42, 'title' => 'Fixture'], 200, ['Content-Type' => 'application/json'])]);

        $this->assertSame('Fixture', app(DiscogsClient::class)->catalogObject('release', '42')['title']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.discogs.test/releases/42'
            && $request->hasHeader('Authorization', 'Discogs token=private-token')
            && $request->hasHeader('User-Agent', 'DiscoTest/1.0 +https://example.test')
            && ! str_contains($request->url(), 'private-token'));
    }

    public function test_catalog_reads_reject_invalid_identifiers_and_missing_credentials(): void
    {
        config()->set('services.discogs.token', null);
        $client = app(DiscogsClient::class);
        $this->assertFalse($client->configured());

        $this->expectException(RuntimeException::class);
        $client->catalogObject('search', 'not-an-id');
    }
}
