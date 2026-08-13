<?php

namespace App\Music\Admin;

use App\Music\Descriptions\TheAudioDbClient;
use App\Music\Discogs\DiscogsClient;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\Notifications\GotifyClient;
use App\Music\Plex\PlexClient;
use RuntimeException;

class ProviderCredentialTester
{
    private const THEAUDIODB_TEST_ARTIST_MBID = '5b11f4ce-a62d-471e-81fc-a69a8278c7da';

    public function test(string $provider, string $secret): void
    {
        match ($provider) {
            'plex' => $this->testPlex($secret),
            'listenbrainz' => (new ListenBrainzClient(token: $secret))->validateToken(),
            'discogs' => (new DiscogsClient($secret))->authenticatedIdentity(),
            'gotify' => (new GotifyClient($secret))->sendTestMessage(),
            'theaudiodb' => $this->testTheAudioDb($secret),
            default => throw new RuntimeException('Unsupported credential provider.'),
        };
    }

    private function testPlex(string $secret): void
    {
        $expectedMachine = (string) config('services.plex.expected_machine_identifier');
        $expectedLibrary = (string) config('services.plex.expected_library_uuid');
        if ($expectedMachine === '' || $expectedLibrary === '') {
            throw new RuntimeException('Plex identity pins are not configured.');
        }

        $client = new PlexClient(token: $secret);
        $identity = $client->identity();
        if (! hash_equals($expectedMachine, $identity['machine_identifier'])) {
            throw new RuntimeException('Plex machine identity did not match its configured pin.');
        }

        $library = $client->musicLibrary((string) config('services.plex.library'));
        if (! is_string($library['uuid']) || ! hash_equals($expectedLibrary, $library['uuid'])) {
            throw new RuntimeException('Plex library identity did not match its configured pin.');
        }
    }

    private function testTheAudioDb(string $secret): void
    {
        if ((new TheAudioDbClient($secret))->artist(self::THEAUDIODB_TEST_ARTIST_MBID) === null) {
            throw new RuntimeException('TheAudioDB did not return the known test artist.');
        }
    }
}
