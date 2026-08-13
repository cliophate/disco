<?php

namespace Tests\Feature;

use App\Music\ListenBrainz\ListenBrainzClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ListenBrainzClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.username', 'fixture-user');
        config()->set('services.listenbrainz.token', 'fixture-secret-token');
        config()->set('services.listenbrainz.timeout', 5);
        config()->set('services.listenbrainz.user_agent', 'Disco tests (offline fixtures)');
        Http::preventStrayRequests();
    }

    public function test_token_is_bound_to_the_exact_user_and_sent_only_in_the_header(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/1/validate-token' => Http::response($this->fixture('validate-token.json'), 200, ['Content-Type' => 'application/json']),
                '/1/user/fixture-user/listens' => Http::response($this->fixture('listens-page-1.json'), 200, ['Content-Type' => 'application/json']),
                default => Http::response([], 404, ['Content-Type' => 'application/json']),
            };
        });

        $client = app(ListenBrainzClient::class);
        $client->validateToken();
        $payload = $client->listens(100, null, 2);

        $this->assertCount(2, $payload['payload']['listens']);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Token fixture-secret-token')
                && $request->hasHeader('User-Agent', 'Disco tests (offline fixtures)')
                && ! str_contains($request->url(), 'fixture-secret-token')
                && $request->data() === [];
        });
    }

    public function test_token_validation_rejects_a_different_username(): void
    {
        Http::fake([
            'https://listenbrainz.test/1/validate-token' => Http::response([
                'valid' => true,
                'user_name' => 'different-user',
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('configured username');

        app(ListenBrainzClient::class)->validateToken();
    }

    public function test_it_retries_only_a_retryable_provider_failure(): void
    {
        Http::fakeSequence('https://listenbrainz.test/1/validate-token')
            ->push([], 503, ['Content-Type' => 'application/json'])
            ->push($this->fixture('validate-token.json'), 200, ['Content-Type' => 'application/json']);

        app(ListenBrainzClient::class)->validateToken();

        Http::assertSentCount(2);
    }

    public function test_it_rejects_non_json_and_non_origin_configuration(): void
    {
        Http::fake([
            'https://listenbrainz.test/1/validate-token' => Http::response('<html />', 200, ['Content-Type' => 'text/html']),
        ]);

        try {
            app(ListenBrainzClient::class)->validateToken();
            $this->fail('A non-JSON provider response must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('content type', $exception->getMessage());
        }

        config()->set('services.listenbrainz.url', 'https://listenbrainz.test/api');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact HTTPS origin');

        app(ListenBrainzClient::class)->validateToken();
    }

    public function test_it_rejects_mutually_exclusive_timestamp_bounds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid ListenBrainz listens request');

        app(ListenBrainzClient::class)->listens(100, 200, 2);
    }

    public function test_it_reads_current_recording_recommendations_and_accepts_no_content(): void
    {
        Http::fakeSequence('https://listenbrainz.test/1/cf/recommendation/user/fixture-user/recording*')
            ->push($this->fixture('recommendations.json'), 200, ['Content-Type' => 'application/json'])
            ->push('', 204);

        $recommendations = app(ListenBrainzClient::class)->recordingRecommendations(2);

        $this->assertSame('fixture-model-v1', $recommendations['payload']['model_id']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $recommendations['payload']['mbids'][0]['recording_mbid']);
        $this->assertSame(0.8, $recommendations['payload']['mbids'][0]['score']);
        $this->assertNull(app(ListenBrainzClient::class)->recordingRecommendations(2));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/cf/recommendation/')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_it_reads_a_symmetric_public_release_window_with_exact_musicbrainz_identities(): void
    {
        $release = [
            'artist_credit_name' => 'Fixture Artist',
            'artist_mbids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            'release_date' => '2026-07-01',
            'release_group_mbid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'release_group_primary_type' => 'EP',
            'release_group_secondary_type' => 'Soundtrack',
            'release_mbid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'release_name' => 'Future Fixture',
            'caa_id' => 123456789,
            'caa_release_mbid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'listen_count' => 4,
            'release_tags' => ['ambient'],
        ];
        Http::fake([
            'https://listenbrainz.test/1/explore/fresh-releases/*' => Http::response([
                'payload' => [
                    'total_count' => 6000,
                    'releases' => array_fill(0, 6000, $release),
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $payload = app(ListenBrainzClient::class)->freshReleases(CarbonImmutable::parse('2026-07-24'), 30, past: true, future: true);

        $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $payload['payload']['releases'][0]['release_group_mbid']);
        $this->assertSame(['Soundtrack'], $payload['payload']['releases'][0]['release_group_secondary_types']);
        $this->assertCount(6000, $payload['payload']['releases']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'days=30')
            && str_contains($request->url(), 'past=true')
            && str_contains($request->url(), 'future=true')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_fresh_releases_skip_records_without_exact_identities(): void
    {
        Http::fake([
            'https://listenbrainz.test/1/explore/fresh-releases/*' => Http::response([
                'payload' => [
                    'total_count' => 1,
                    'releases' => [[
                        'artist_credit_name' => 'Fixture Artist',
                        'artist_mbids' => [],
                        'release_date' => '2026-08-01',
                        'release_group_mbid' => 'not-an-mbid',
                        'release_group_primary_type' => 'Album',
                        'release_mbid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                        'release_name' => 'Fuzzy Fixture',
                        'listen_count' => 0,
                        'release_tags' => [],
                    ]],
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $payload = app(ListenBrainzClient::class)->freshReleases(CarbonImmutable::parse('2026-07-24'), 30);

        $this->assertSame([], $payload['payload']['releases']);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/listenbrainz/{$name}"));
    }
}
