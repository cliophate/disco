<?php

namespace Tests\Feature;

use App\Music\Plex\PlexClient;
use App\Music\Plex\PlexSyncService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PlexClientTest extends TestCase
{
    public function test_it_reads_identity_library_and_items_without_exposing_the_token(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.timeout', 5);
        Http::fake([
            'https://plex.test/identity*' => Http::response($this->fixture('identity.xml'), 200),
            'https://plex.test/library/sections' => Http::response($this->fixture('sections.xml'), 200),
            'https://plex.test/library/sections/7/all?*' => Http::response($this->fixture('albums.xml'), 200),
        ]);

        $client = new PlexClient('https://plex.test', 'fixture-token');

        $this->assertSame('test-machine', $client->identity()['machine_identifier']);
        $this->assertSame('7', $client->musicLibrary('Music')['key']);
        $items = $client->libraryItems('7', 9);
        $this->assertCount(1, $items);
        $this->assertSame('Sometimes I Might Be Introvert', $items[0]['attributes']['title']);
        $this->assertSame(['mbid://22222222-2222-4222-8222-222222222222'], $items[0]['guids']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Token', 'fixture-token'));
        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'fixture-token'));
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) !== '/identity'
            || ! $request->hasHeader('X-Plex-Token'));
    }

    public function test_it_rejects_plain_http_by_default(): void
    {
        config()->set('services.plex.allow_insecure_http', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Plex HTTP is disabled');

        (new PlexClient('http://plex.test', 'fixture-token'))->identity();
    }

    public function test_it_rejects_a_base_url_with_a_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be an origin');

        (new PlexClient('https://plex.test/library', 'fixture-token'))->identity();
    }

    public function test_a_write_sync_requires_an_expected_machine_identifier(): void
    {
        config()->set('services.plex.expected_machine_identifier', '');
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake([
            'https://plex.test/identity*' => Http::response($this->fixture('identity.xml'), 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLEX_EXPECTED_MACHINE_IDENTIFIER is required');

        (new PlexSyncService(new PlexClient('https://plex.test', 'fixture-token')))->sync();
    }

    public function test_it_paginates_until_plex_total_size_is_satisfied(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['X-Plex-Container-Start'] ?? 0);
            $ratingKey = $offset === 0 ? '1' : '2';

            return Http::response(<<<XML
                <MediaContainer size="1" totalSize="2">
                  <Directory ratingKey="{$ratingKey}" title="Album {$ratingKey}" />
                </MediaContainer>
                XML, 200, ['Content-Type' => 'application/xml']);
        });

        $items = (new PlexClient('https://plex.test', 'fixture-token'))->libraryItems('7', 9);

        $this->assertCount(2, $items);
        $this->assertSame(['1', '2'], array_column(array_column($items, 'attributes'), 'ratingKey'));
        Http::assertSentCount(2);
    }

    public function test_it_projects_typed_audio_parts_without_retaining_file_paths(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake([
            'https://plex.test/library/sections/7/all?*' => Http::response($this->fixture('tracks.xml'), 200, ['Content-Type' => 'application/xml']),
        ]);

        $track = (new PlexClient('https://plex.test', 'fixture-token'))->libraryItems('7', 10)[0];

        $this->assertSame([[
            'media_id' => '401',
            'part_id' => '501',
            'part_key' => '/library/parts/501/1700000100/file.flac',
            'container' => 'flac',
            'audio_codec' => 'flac',
            'channels' => '2',
            'bit_depth' => '24',
            'sample_rate_hz' => '96000',
            'bitrate_kbps' => '951',
            'size_bytes' => '43000000',
            'duration_ms' => '362000',
        ]], $track['media_parts']);
        $this->assertStringNotContainsString('/music/', json_encode($track, JSON_THROW_ON_ERROR));
    }

    public function test_it_sanitizes_active_music_sessions(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake([
            'https://plex.test/status/sessions' => Http::response(<<<'XML'
                <MediaContainer size="3">
                  <Track ratingKey="301" parentRatingKey="201" grandparentRatingKey="101"><User title="private"/><Player address="private" state="playing"/><Session id="private"/></Track>
                  <Track ratingKey="302" parentRatingKey="201"><Player state="stopped"/></Track>
                  <Video ratingKey="999"><Player state="playing"/></Video>
                </MediaContainer>
                XML, 200, ['Content-Type' => 'application/xml']),
        ]);

        $sessions = (new PlexClient('https://plex.test', 'fixture-token'))->activeSessions();

        $this->assertSame([['rating_key' => '301', 'parent_rating_key' => '201', 'grandparent_rating_key' => '101', 'state' => 'playing']], $sessions);
        $this->assertArrayNotHasKey('user', $sessions[0]);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Token', 'fixture-token') && ! str_contains($request->url(), 'fixture-token'));
    }

    public function test_original_parts_and_playback_writes_are_pinned_and_token_free_in_urls(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/identity') {
                return Http::response('<MediaContainer machineIdentifier="test-machine" />', 200, ['Content-Type' => 'application/xml']);
            }
            if ($path === '/library/parts/501/1700000100/file.flac') {
                return Http::response('audio', 206, ['Content-Type' => 'audio/flac', 'Content-Range' => 'bytes 0-4/5', 'Content-Length' => '5']);
            }

            return Http::response('', 200, ['Content-Type' => 'application/xml']);
        });
        $client = new PlexClient('https://plex.test', 'fixture-token');

        $response = $client->originalPart('/library/parts/501/1700000100/file.flac', '501', 'bytes=0-4', 'audio/flac');
        $client->playbackTimeline('301', 'playing', 1000, 5000, 'disco-'.str_repeat('a', 32));
        $client->scrobble('301', 'disco-'.str_repeat('a', 32));

        $this->assertSame(206, $response->status());
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/library/parts/501/1700000100/file.flac'
            && $request->hasHeader('Range', 'bytes=0-4') && $request->hasHeader('X-Plex-Token', 'fixture-token'));
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/:/timeline'
            && $request->hasHeader('X-Plex-Client-Identifier'));
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/:/scrobble');
        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'fixture-token') && ! str_contains($request->url(), 'transcode'));
    }

    public function test_an_unvalidated_media_part_path_is_rejected_before_any_request(): void
    {
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid media part path');

        (new PlexClient('https://plex.test', 'fixture-token'))
            ->originalPart('/library/parts/999/1700000100/file.flac', '501', null, 'audio/flac');
    }

    public function test_it_fetches_only_validated_raster_artwork_with_a_header_token(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="test-machine" friendlyName="Test" />', 200, ['Content-Type' => 'application/xml']),
            'https://plex.test/library/metadata/201/thumb/1700000000' => Http::response(
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $artwork = (new PlexClient('https://plex.test', 'fixture-token'))
            ->artwork('/library/metadata/201/thumb/1700000000', '201');

        $this->assertSame('image/png', $artwork['mime_type']);
        $this->assertSame([1, 1], [$artwork['width'], $artwork['height']]);
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Token', 'fixture-token')
            && ! str_contains($request->url(), 'fixture-token'));
    }

    public function test_it_rejects_an_artwork_path_for_another_item_before_requesting_it(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid artwork path');

        (new PlexClient('https://plex.test', 'fixture-token'))
            ->artwork('/library/metadata/999/thumb/1700000000', '201');
    }

    public function test_it_accepts_an_artwork_path_inherited_from_the_items_direct_parent(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="test-machine" friendlyName="Test" />', 200, ['Content-Type' => 'application/xml']),
            'https://plex.test/library/metadata/200/thumb/1700000000' => Http::response(
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        $artwork = (new PlexClient('https://plex.test', 'fixture-token'))
            ->artwork('/library/metadata/200/thumb/1700000000', '201', '200');

        $this->assertSame('image/png', $artwork['mime_type']);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/library/metadata/200/thumb/1700000000'
            && $request->hasHeader('X-Plex-Token', 'fixture-token'));
    }

    public function test_it_rejects_an_artwork_path_outside_the_item_and_direct_parent(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid artwork path');

        (new PlexClient('https://plex.test', 'fixture-token'))
            ->artwork('/library/metadata/999/thumb/1700000000', '201', '200');
    }

    public function test_artwork_identity_mismatch_fails_before_sending_the_token(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.expected_machine_identifier', 'expected-machine');
        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="other-machine" />', 200, ['Content-Type' => 'application/xml']),
        ]);

        try {
            (new PlexClient('https://plex.test', 'fixture-token'))
                ->artwork('/library/metadata/201/thumb/1700000000', '201');
            $this->fail('A mismatched machine must abort artwork ingestion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('machine identifier mismatch', $exception->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Plex-Token'));
    }

    public function test_artwork_rejects_non_raster_content_types(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="test-machine" />', 200, ['Content-Type' => 'application/xml']),
            'https://plex.test/library/metadata/201/thumb/1700000000' => Http::response($png, 200, ['Content-Type' => 'image/svg+xml']),
        ]);

        try {
            (new PlexClient('https://plex.test', 'fixture-token'))
                ->artwork('/library/metadata/201/thumb/1700000000', '201');
            $this->fail('A non-raster declared image type must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unsupported content type', $exception->getMessage());
        }
    }

    public function test_artwork_rejects_oversized_transports(): void
    {
        config()->set('services.plex.allow_insecure_http', false);
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake([
            'https://plex.test/identity' => Http::response('<MediaContainer machineIdentifier="test-machine" />', 200, ['Content-Type' => 'application/xml']),
            'https://plex.test/library/metadata/201/thumb/1700000000' => Http::response($png, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) (21 * 1024 * 1024),
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport safety policy');
        (new PlexClient('https://plex.test', 'fixture-token'))
            ->artwork('/library/metadata/201/thumb/1700000000', '201');
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/plex/{$name}"));
    }
}
