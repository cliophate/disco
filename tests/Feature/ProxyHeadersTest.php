<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProxyHeadersTest extends TestCase
{
    public function test_trusted_proxy_headers_preserve_https_urls(): void
    {
        Route::middleware('web')->get('/api/_proxy-test', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'asset_url' => url('/build/app.js'),
        ]));

        config()->set('app.url', 'https://disco.example.test');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Host' => 'disco.example.test',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/api/_proxy-test')
            ->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('asset_url', 'https://disco.example.test/build/app.js');
    }
}
