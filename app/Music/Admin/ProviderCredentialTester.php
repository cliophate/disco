<?php

namespace App\Music\Admin;

use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\Plex\PlexClient;
use RuntimeException;

class ProviderCredentialTester
{
    public function test(string $provider, string $secret): void
    {
        match ($provider) {
            'plex' => $this->testPlex($secret),
            'listenbrainz' => (new ListenBrainzClient(token: $secret))->validateToken(),
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
}
