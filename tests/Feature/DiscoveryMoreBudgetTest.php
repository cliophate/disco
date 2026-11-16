<?php

namespace Tests\Feature;

use App\Models\DiscoveryPick;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\Discovery\DiscoveryBatchGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\DiscoveryTestCase;

class DiscoveryMoreBudgetTest extends DiscoveryTestCase
{
    private function fakeCatalog(): void
    {
        // Two empty artists, then ten artists with albums and newer EPs.
        for ($i = 1; $i <= 12; $i++) {
            $this->listen('Artist '.$i, $this->mbid($i));
        }
        $artwork = Mockery::mock(CoverArtArchiveIngestor::class);
        $artwork->shouldReceive('ingest')->andReturnNull();
        $this->app->instance(CoverArtArchiveIngestor::class, $artwork);
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/ws/2/release-group') {
                $artist = (int) substr($request['artist'], 0, 8);
                $groups = $artist <= 2 ? [] : array_map(fn ($n) => [
                    'id' => $this->mbid($artist * 100 + $n), 'title' => ($n === 1 ? 'Album ' : 'EP ').$artist,
                    'primary-type' => $n === 1 ? 'Album' : 'EP', 'first-release-date' => $n === 1 ? '2025-01-01' : '2026-01-01',
                    'artist-credit' => [['artist' => ['id' => $this->mbid($artist)]]],
                ], [2, 1]);

                return Http::response(['release-groups' => $groups, 'release-group-count' => count($groups)], 200, ['Content-Type' => 'application/json']);
            }
            $id = basename($path);
            $number = (int) substr($id, 0, 8);
            if (str_starts_with($path, '/ws/2/release-group/')) {
                $artist = intdiv($number, 100);
                $payload = [
                    'id' => $id, 'title' => 'Album '.$artist, 'primary-type' => 'Album', 'first-release-date' => '2025-01-01',
                    'artist-credit' => [['artist' => ['id' => $this->mbid($artist)]]],
                    'releases' => array_map(fn ($n) => ['id' => $this->mbid($number * 10 + $n)], [1, 2, 3, 4]),
                ];
            } else {
                $payload = ['id' => $id, 'title' => 'Edition', 'release-group' => ['id' => $this->mbid(intdiv($number, 10))]];
            }

            return Http::response($payload, 200, ['Content-Type' => 'application/json']);
        });
    }

    public function test_more_has_its_own_actual_call_budget_and_prefers_albums_over_newer_eps(): void
    {
        $this->fakeCatalog();
        config()->set('discovery.album_lookup_limit', 1);
        $batch = app(DiscoveryBatchGenerator::class)->generate('more');
        $picks = DiscoveryPick::query()->orderBy('position')->get();
        $this->assertCount(10, $picks);
        $this->assertCount(10, $picks->unique('artist_mbid'));
        $this->assertCount(10, $picks->unique('artist_name'));
        $this->assertSame('Album 3', $picks[0]->title);
        $this->assertSame('An unheard album by Artist 3', $picks[0]->reason);
        // One browse per artist, one group and four edition requests per pick.
        $this->assertSame(62, $batch->summary['musicbrainz_calls']);
        Http::assertSentCount(62);
    }

    public function test_the_call_budget_includes_nested_edition_requests(): void
    {
        $this->fakeCatalog();
        config()->set('discovery.more_musicbrainz_call_limit', 9);
        $batch = app(DiscoveryBatchGenerator::class)->generate('more');
        $this->assertSame(9, $batch->summary['musicbrainz_calls']);
        $this->assertSame(1, $batch->summary['picks']);
        $this->assertSame(0, $batch->summary['provider_failures']);
        Http::assertSentCount(9);
    }
}
