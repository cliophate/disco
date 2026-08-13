<?php

namespace Tests\Feature;

use App\Music\Admin\ProviderCredentialTester;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderCredentialTesterTest extends TestCase
{
    public function test_it_tests_candidates_against_trusted_provider_endpoints_without_leaking_them(): void
    {
        config()->set('services.plex.url', 'https://plex.test');
        config()->set('services.plex.library', 'Music');
        config()->set('services.plex.expected_machine_identifier', 'expected-machine');
        config()->set('services.plex.expected_library_uuid', 'expected-library');
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.username', 'expected-user');
        config()->set('services.discogs.url', 'https://discogs.test');
        config()->set('services.discogs.rate_interval_ms', 0);
        config()->set('services.gotify.url', 'https://gotify.test');
        config()->set('services.theaudiodb.rate_interval_ms', 0);

        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/identity' => Http::response('<MediaContainer machineIdentifier="expected-machine" />', 200, ['Content-Type' => 'application/xml']),
                '/library/sections' => Http::response('<MediaContainer><Directory key="7" uuid="expected-library" title="Music" type="artist" /></MediaContainer>', 200, ['Content-Type' => 'application/xml']),
                '/1/validate-token' => Http::response(['valid' => true, 'user_name' => 'expected-user'], 200, ['Content-Type' => 'application/json']),
                '/oauth/identity' => Http::response(['id' => 42, 'username' => 'expected-discogs-user'], 200, ['Content-Type' => 'application/json']),
                '/message' => Http::response(['id' => 99], 200, ['Content-Type' => 'application/json']),
                '/api/v1/json/audio-secret/artist-mb.php' => Http::response(['artists' => [[
                    'idArtist' => '111',
                    'strMusicBrainzID' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da',
                ]]], 200, ['Content-Type' => 'application/json']),
                default => Http::response([], 404, ['Content-Type' => 'application/json']),
            };
        });

        $tester = app(ProviderCredentialTester::class);
        $tester->test('plex', 'plex-secret');
        $tester->test('listenbrainz', 'listen-secret');
        $tester->test('discogs', 'discogs-secret');
        $tester->test('gotify', 'gotify-secret');
        $tester->test('theaudiodb', 'audio-secret');

        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/library/sections'
            && $request->hasHeader('X-Plex-Token', 'plex-secret'));
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/1/validate-token'
            && $request->hasHeader('Authorization', 'Token listen-secret'));
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/oauth/identity'
            && $request->hasHeader('Authorization', 'Discogs token=discogs-secret'));
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/message'
            && $request->hasHeader('X-Gotify-Key', 'gotify-secret') && $request['priority'] === -2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/audio-secret/artist-mb.php?i=5b11f4ce-a62d-471e-81fc-a69a8278c7da'));
    }

    public function test_plex_candidate_must_match_both_configured_identity_pins(): void
    {
        config()->set('services.plex.url', 'https://plex.test');
        config()->set('services.plex.library', 'Music');
        config()->set('services.plex.expected_machine_identifier', 'expected-machine');
        config()->set('services.plex.expected_library_uuid', 'expected-library');
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="other-machine" />', 200, ['Content-Type' => 'application/xml']),
        ]);

        $this->expectExceptionMessage('machine identity');
        app(ProviderCredentialTester::class)->test('plex', 'candidate-secret');
    }
}
