<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
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

    public function test_vite_tags_use_the_configured_https_asset_origin(): void
    {
        $buildDirectory = 'build-vite-asset-origin-test';
        $manifestDirectory = public_path($buildDirectory);
        $manifestPath = $manifestDirectory.'/manifest.json';

        if (! is_dir($manifestDirectory)) {
            mkdir($manifestDirectory, 0755, true);
        }

        file_put_contents($manifestPath, json_encode([
            'resources/css/app.css' => [
                'file' => 'assets/app.css',
                'src' => 'resources/css/app.css',
                'isEntry' => true,
            ],
            'resources/js/app.tsx' => [
                'file' => 'assets/app.js',
                'src' => 'resources/js/app.tsx',
                'isEntry' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            config()->set('app.asset_url', 'https://disco.example.test');

            $tags = (string) Vite::useBuildDirectory($buildDirectory)
                ->withEntryPoints(['resources/css/app.css', 'resources/js/app.tsx'])
                ->toHtml();

            $this->assertStringContainsString("https://disco.example.test/{$buildDirectory}/assets/app.css", $tags);
            $this->assertStringContainsString("https://disco.example.test/{$buildDirectory}/assets/app.js", $tags);
            $this->assertStringNotContainsString('http://', $tags);
        } finally {
            @unlink($manifestPath);
            @rmdir($manifestDirectory);
        }
    }
}
