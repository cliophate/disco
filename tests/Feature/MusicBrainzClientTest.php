<?php

namespace Tests\Feature;

use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MusicBrainzClientTest extends TestCase
{
    public function test_it_fetches_a_scoped_entity_as_json(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.user_agent', 'Disco test contact');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $mbid = '11111111-1111-4111-8111-111111111111';
        Http::fake([
            "https://musicbrainz.test/ws/2/artist/{$mbid}*" => Http::response([
                'id' => $mbid,
                'name' => 'Little Simz',
                'genres' => [['name' => 'hip hop', 'count' => 8]],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $payload = app(MusicBrainzClient::class)->entity('artist', $mbid);

        $this->assertSame('Little Simz', $payload['name']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'Disco test contact')
            && str_contains($request->url(), 'inc=genres%2Burl-rels'));
    }

    public function test_it_rejects_an_insecure_provider_origin(): void
    {
        config()->set('services.musicbrainz.url', 'http://musicbrainz.test/ws/2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trusted HTTPS');

        app(MusicBrainzClient::class)->entity('artist', '11111111-1111-4111-8111-111111111111');
    }

    public function test_release_group_requests_exact_url_relationships(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $mbid = '22222222-2222-4222-8222-222222222222';
        Http::fake([
            "https://musicbrainz.test/ws/2/release-group/{$mbid}*" => Http::response(['id' => $mbid], 200, ['Content-Type' => 'application/json']),
        ]);

        app(MusicBrainzClient::class)->entity('release-group', $mbid);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'url-rels'));
    }

    public function test_it_retries_a_streamed_service_unavailable_response(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $mbid = '33333333-3333-4333-8333-333333333333';
        Http::fakeSequence()
            ->push(['error' => 'temporarily unavailable'], 503, ['Content-Type' => 'application/json'])
            ->push(['id' => $mbid, 'name' => 'Recovered Artist'], 200, ['Content-Type' => 'application/json']);

        $payload = app(MusicBrainzClient::class)->entity('artist', $mbid);

        $this->assertSame('Recovered Artist', $payload['name']);
        Http::assertSentCount(2);
    }

    public function test_release_group_search_is_bounded_and_cached(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        Http::fake([
            'https://musicbrainz.test/ws/2/release-group*' => Http::response([
                'release-groups' => [[
                    'id' => '44444444-4444-4444-8444-444444444444',
                    'title' => 'Ambiguous Album',
                    'primary-type' => 'Album',
                ]],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $client = app(MusicBrainzClient::class);
        $this->assertCount(1, $client->searchReleaseGroups('Ambiguous Album', 12));
        $this->assertCount(1, $client->searchReleaseGroups('Ambiguous Album', 12));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'limit=12') && str_contains($request->url(), 'offset=0'));
    }

    public function test_release_group_releases_are_bounded_and_parent_verified(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $groupMbid = '55555555-5555-4555-8555-555555555555';
        $releaseMbid = '66666666-6666-4666-8666-666666666666';
        Http::fake([
            'https://musicbrainz.test/ws/2/release*' => Http::response(['releases' => [[
                'id' => $releaseMbid,
                'status' => 'Official',
                'release-group' => ['id' => $groupMbid],
            ]]], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->assertSame($releaseMbid, app(MusicBrainzClient::class)->releaseGroupReleases($groupMbid)[0]['id']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'release-group='.$groupMbid)
            && str_contains($request->url(), 'limit=100') && str_contains($request->url(), 'offset=0'));
    }

    public function test_artist_release_groups_are_exact_paginated_and_bounded(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $artistMbid = '77777777-7777-4777-8777-777777777777';
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['offset'] ?? 0);

            return Http::response([
                'release-group-count' => 3,
                'release-groups' => $offset === 0 ? [
                    ['id' => '88888888-8888-4888-8888-888888888888'],
                    ['id' => '99999999-9999-4999-8999-999999999999'],
                ] : [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']],
            ], 200, ['Content-Type' => 'application/json']);
        });

        $result = app(MusicBrainzClient::class)->artistReleaseGroups($artistMbid, 2, 2);

        $this->assertCount(3, $result['release_groups']);
        $this->assertSame(2, $result['pages']);
        $this->assertFalse($result['truncated']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'artist='.$artistMbid)
            && str_contains($request->url(), 'inc=artist-credits'));
    }

    public function test_official_release_browse_fails_closed_when_its_page_budget_is_exhausted(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $groupMbid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        Http::fake([
            'https://musicbrainz.test/ws/2/release*' => Http::response([
                'release-count' => 2,
                'releases' => [[
                    'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                    'status' => 'Promotion',
                    'release-group' => ['id' => $groupMbid],
                ]],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bounded page limit');

        app(MusicBrainzClient::class)->officialRelease($groupMbid, 1, 1);
    }
}
