<?php

namespace Tests\Feature;

use App\Music\Discovery\DiscoveryProviderStats;
use App\Music\Discovery\ProviderCallBudgetExceeded;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoveryProviderTest extends TestCase
{
    private string $seed = '11111111-1111-4111-8111-111111111111';

    private string $candidate = '22222222-2222-4222-8222-222222222222';

    public function test_artist_radio_drops_the_seed_and_checks_artist_identity(): void
    {
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        Http::fake(['https://listenbrainz.test/*' => Http::response([
            $this->seed => [['similar_artist_mbid' => $this->seed, 'similar_artist_name' => 'Seed']],
            $this->candidate => [['similar_artist_mbid' => $this->candidate, 'similar_artist_name' => 'Candidate']],
            '33333333-3333-4333-8333-333333333333' => [['similar_artist_mbid' => $this->candidate, 'similar_artist_name' => 'Wrong artist']],
        ], 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame([$this->candidate => 'Candidate'], app(ListenBrainzClient::class)->artistRadio($this->seed));
        Http::assertSent(fn (Request $request) => $request['mode'] === 'medium' && (int) $request['pop_begin'] === 0 && (int) $request['pop_end'] === 100 && ! $request->hasHeader('Authorization'));
    }

    public function test_popularity_uses_post_and_accepts_a_json_list_with_missing_counts(): void
    {
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        Http::fake(['https://listenbrainz.test/1/popularity/artist' => Http::response([
            ['artist_mbid' => $this->seed, 'total_user_count' => 200, 'total_listen_count' => 1000],
            ['artist_mbid' => $this->candidate, 'total_user_count' => null, 'total_listen_count' => null],
        ], 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame([$this->seed => 200], app(ListenBrainzClient::class)->artistPopularity([$this->seed, $this->candidate]));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['artist_mbids'] === [$this->seed, $this->candidate]);
    }

    public function test_top_release_groups_decode_a_list_and_keep_provider_counts(): void
    {
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        $row = ['release_group_mbid' => $this->candidate, 'total_listen_count' => 100, 'release_group' => ['type' => 'Album'], 'artist' => ['name' => 'Synthetic']];
        Http::fake(['https://listenbrainz.test/*' => Http::response([$row], 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame([$row], app(ListenBrainzClient::class)->topReleaseGroups($this->seed));
    }

    public function test_personal_releases_use_the_user_endpoint_and_handle_no_content(): void
    {
        config()->set('services.listenbrainz.url', 'https://listenbrainz.test');
        config()->set('services.listenbrainz.username', 'fixture-listener');
        Http::fake(['https://listenbrainz.test/*' => Http::response('', 204)]);
        $this->assertSame([], app(ListenBrainzClient::class)->userFreshReleases(90)['payload']['releases']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/1/user/fixture-listener/fresh_releases') && (int) $request['days'] === 90 && $request['past'] === 'true' && $request['future'] === 'true');
    }

    public function test_personal_release_horizon_is_validated_before_a_request(): void
    {
        $this->expectExceptionMessage('between 1 and 90');
        app(ListenBrainzClient::class)->userFreshReleases(91);
    }

    public function test_musicbrainz_failure_categories_keep_endpoint_and_status_without_urls(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        $stats = app(DiscoveryProviderStats::class);
        $stats->start();
        Http::fake(['https://musicbrainz.test/*' => Http::response([], 404)]);
        try {
            app(MusicBrainzClient::class)->entity('release', $this->seed);
            $this->fail('The request should fail.');
        } catch (RequestException) {
            $this->assertSame(['musicbrainz.release:404' => 1], $stats->failures);
            $this->assertSame(1, $stats->musicBrainzCalls);
        }
    }

    public function test_actual_request_budget_counts_browse_pages_and_retries(): void
    {
        config()->set('services.musicbrainz.url', 'https://musicbrainz.test/ws/2');
        config()->set('services.musicbrainz.rate_interval_ms', 0);
        $stats = app(DiscoveryProviderStats::class);
        $stats->start(3);
        Http::fakeSequence()->push(['release-groups' => [['id' => $this->candidate]], 'release-group-count' => 2], 200, ['Content-Type' => 'application/json'])
            ->push(['release-groups' => [['id' => $this->seed]], 'release-group-count' => 2], 200, ['Content-Type' => 'application/json'])
            ->push([], 503);
        $groups = app(MusicBrainzClient::class)->artistReleaseGroups($this->seed, 1);
        $this->assertSame(2, $groups['pages']);
        try {
            app(MusicBrainzClient::class)->entity('release', $this->candidate);
            $this->fail('The retry should exhaust the budget.');
        } catch (ProviderCallBudgetExceeded) {
            $this->assertSame(3, $stats->musicBrainzCalls);
            Http::assertSentCount(3);
        }
    }
}
