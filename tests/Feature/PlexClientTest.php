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
