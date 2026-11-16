<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryPick;
use App\Models\ExternalIdentifier;
use App\Models\ReleaseGroup;
use App\Music\Discovery\DiscoveryAlbumResolver;
use App\Music\Discovery\DiscoveryBatchGenerator;
use App\Music\Discovery\TasteProfile;
use App\Music\ListenBrainz\ListenBrainzClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\DiscoveryTestCase;

class DiscoverySoundtrackTest extends DiscoveryTestCase
{
    private function providers(array $radio, array $soundtrackArtists = [], array $tags = [], int $tagStatus = 200): array
    {
        $lb = Mockery::mock(ListenBrainzClient::class);
        $lb->shouldReceive('configured')->andReturnTrue();
        $lb->shouldReceive('artistRadio')->andReturnUsing(fn ($id) => $radio[$id] ?? []);
        $lb->shouldReceive('artistPopularity')->andReturn([]);
        $lb->shouldReceive('topReleaseGroups')->andReturnUsing(fn ($id) => [['release_group_mbid' => $this->mbid(1000 + (int) substr($id, 0, 8))]])->byDefault();
        $albums = Mockery::mock(DiscoveryAlbumResolver::class);
        $albums->shouldReceive('group')->andReturnUsing(fn ($id, $artist) => [
            'id' => $id, 'title' => 'Synthetic album '.$id, 'primary-type' => 'Album',
            'secondary-types' => in_array($artist, $soundtrackArtists, true) ? ['Soundtrack'] : [],
        ]);
        $albums->shouldReceive('prepare')->andReturnUsing(fn ($group, $artist, $name, $release = null) => [
            'artist_mbid' => $artist, 'artist_name' => $name, 'release_group_mbid' => $group['id'],
            'title' => $group['title'], 'release_type' => 'Album', 'qobuz_url' => 'https://open.qobuz.com/album/fixture', 'qobuz_status' => 'exact',
        ]);
        Http::fake(['https://musicbrainz.test/ws/2/artist/*' => function ($request) use ($tags, $tagStatus) {
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response(['id' => $id, ...($tags[$id] ?? [])], $tagStatus, ['Content-Type' => 'application/json']);
        }]);
        $this->app->instance(ListenBrainzClient::class, $lb);
        $this->app->instance(DiscoveryAlbumResolver::class, $albums);

        return [$lb, $albums];
    }

    private function radio(array $numbers): array
    {
        $radio = [];
        foreach ($numbers as $number) {
            $radio[$this->mbid($number)] = 'Synthetic artist '.$number;
        }

        return $radio;
    }

    public function test_release_type_cap_skips_candidates_and_fills_beyond_the_lookup_limit(): void
    {
        config()->set(['discovery.batch_size' => 3, 'discovery.soundtrack_cap' => 1, 'discovery.album_lookup_limit' => 3, 'discovery.seed_cap' => 20]);
        $this->listen('Synthetic seed', $this->mbid(1));
        $this->providers([$this->mbid(1) => $this->radio(range(10, 18))], array_map($this->mbid(...), range(10, 15)));
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame([$this->mbid(10), $this->mbid(16), $this->mbid(17)], DiscoveryPick::query()->orderBy('position')->pluck('artist_mbid')->all());
        $this->assertSame(1, $batch->summary['soundtrack_picks']);
        $this->assertSame(5, $batch->summary['soundtrack_skipped']);
        $this->assertEquals(['release_type' => 6, 'artist_tags' => 0, 'seed_weight' => 0], $batch->summary['soundtrack_rule_hits']);
        $this->assertSame(8, $batch->summary['musicbrainz_calls']);
        $this->assertSame(0, $batch->summary['provider_failures']);
        Http::assertSentCount(8);
        Http::assertSent(fn ($request) => $request['inc'] === 'genres+tags');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $this->mbid(18)));
        $this->assertSame(9, DiscoveryCandidate::query()->count());
    }

    public function test_all_soundtrack_tags_and_genres_match_but_unrelated_tags_do_not(): void
    {
        config()->set(['discovery.batch_size' => 2, 'discovery.soundtrack_cap' => 0, 'discovery.album_lookup_limit' => 2, 'discovery.seed_cap' => 30]);
        $this->listen('Synthetic seed', $this->mbid(1));
        $tags = [];
        $number = 10;
        foreach (['genres', 'tags'] as $field) {
            foreach (['video game music', 'vgm', 'soundtrack', 'chiptune', 'film score', 'film composer', 'composer', 'game music', 'video game', 'anime soundtrack'] as $tag) {
                $tags[$this->mbid($number++)] = [$field => [['name' => ' '.strtoupper($tag).' ']]];
            }
        }
        $tags[$this->mbid(30)] = ['genres' => [['name' => 'hip hop']]];
        $tags[$this->mbid(31)] = ['tags' => [['name' => 'metal']]];
        $this->providers([$this->mbid(1) => $this->radio(range(10, 31))], tags: $tags);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame([$this->mbid(30), $this->mbid(31)], DiscoveryPick::query()->orderBy('position')->pluck('artist_mbid')->all());
        $this->assertSame(20, $batch->summary['soundtrack_skipped']);
        $this->assertEquals(['release_type' => 0, 'artist_tags' => 20, 'seed_weight' => 0], $batch->summary['soundtrack_rule_hits']);
    }

    public function test_seed_weight_uses_log_scores_and_all_referring_seeds(): void
    {
        config()->set(['discovery.batch_size' => 4, 'discovery.soundtrack_cap' => 1, 'discovery.seed_cap' => 20]);
        $this->listen('Synthetic soundtrack seed', $this->mbid(1), album: 'Synthetic OST');
        $this->listen('Synthetic regular seed', $this->mbid(2));
        $this->listen('Synthetic strong seed', $this->mbid(3));
        $this->listen('Synthetic strong seed', $this->mbid(3));
        $this->providers([
            $this->mbid(1) => $this->radio([10, 11, 12]),
            $this->mbid(2) => $this->radio([11]),
            $this->mbid(3) => $this->radio([12, 13]),
        ]);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        // Candidate 12 has less than half soundtrack weight. Candidate 11 has exactly half.
        $this->assertSame([$this->mbid(12), $this->mbid(11), $this->mbid(13)], DiscoveryPick::query()->orderBy('position')->pluck('artist_mbid')->all());
        $this->assertSame(1, $batch->summary['soundtrack_picks']);
        $this->assertSame(1, $batch->summary['soundtrack_skipped']);
        $this->assertSame(2, $batch->summary['soundtrack_rule_hits']['seed_weight']);
    }

    public function test_multiple_rule_hits_count_one_soundtrack_pick_and_one_skip(): void
    {
        config()->set(['discovery.soundtrack_cap' => 1, 'discovery.seed_cap' => 10]);
        $this->listen('Synthetic soundtrack seed', $this->mbid(1), album: 'Synthetic soundtrack');
        $this->providers([$this->mbid(1) => $this->radio([10, 11])], [$this->mbid(10), $this->mbid(11)], [
            $this->mbid(10) => ['genres' => [['name' => 'composer']]],
            $this->mbid(11) => ['tags' => [['name' => 'vgm']]],
        ]);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame(1, $batch->summary['picks']);
        $this->assertSame(1, $batch->summary['soundtrack_picks']);
        $this->assertSame(1, $batch->summary['soundtrack_skipped']);
        $this->assertEquals(['release_type' => 2, 'artist_tags' => 2, 'seed_weight' => 2], $batch->summary['soundtrack_rule_hits']);
    }

    public function test_seed_classification_uses_mapped_release_types_and_recent_listen_counts(): void
    {
        $entity = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Synthetic score', 'sort_name' => 'Synthetic score']);
        ReleaseGroup::query()->create(['entity_id' => $entity->id, 'primary_type' => 'album', 'secondary_types' => ['Soundtrack']]);
        ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => 'musicbrainz.release_group', 'value' => $this->mbid(100), 'status' => 'active']);
        $event = $this->listen('Synthetic typed seed', $this->mbid(1), days: 500, album: 'Plain title');
        DB::table('activity.listen_identities')->where('listening_event_id', $event->id)->update(['release_group_mbid' => $this->mbid(100)]);
        $this->listen('Synthetic typed seed', $this->mbid(1), album: 'Regular album');
        $this->listen('Synthetic typed seed', $this->mbid(1), days: 800, album: 'Old regular album');
        $this->listen('Synthetic old seed', $this->mbid(2), days: 800, album: 'Synthetic OST');
        $this->listen('Synthetic old seed', $this->mbid(2), album: 'Regular album');
        $this->listen('Synthetic minority seed', $this->mbid(3), album: 'Synthetic OST');
        $this->listen('Synthetic minority seed', $this->mbid(3), album: 'Regular album');
        $this->listen('Synthetic minority seed', $this->mbid(3), album: 'Another regular album');
        $taste = app(TasteProfile::class)->load();
        $this->assertTrue($taste->soundtrackSeed($this->mbid(1)));
        $this->assertFalse($taste->soundtrackSeed($this->mbid(2)));
        $this->assertFalse($taste->soundtrackSeed($this->mbid(3)));
        config()->set('discovery.soundtrack_seed_threshold', 0.6);
        $this->assertFalse($taste->load()->soundtrackSeed($this->mbid(1)));
        config()->set('discovery.soundtrack_seed_threshold', 0.3);
        $this->assertTrue($taste->load()->soundtrackSeed($this->mbid(3)));
    }

    public function test_seed_title_patterns_are_case_insensitive_and_preserve_word_boundaries(): void
    {
        $titles = ['soundtrack', 'OST', 'original score', 'orchestrated', 'video game', 'game music', 'music from', 'Pokémon', 'Pokemon', 'Zelda', 'Final Fantasy', 'Mario', 'Nintendo', 'VGM'];
        foreach ($titles as $number => $title) {
            $this->listen('Synthetic seed '.$number, $this->mbid($number + 1), album: 'Synthetic '.strtoupper($title).' collection');
        }
        foreach (['Lost', 'Postcards', 'Marionette', 'Regular album'] as $number => $title) {
            $this->listen('Synthetic regular seed '.$number, $this->mbid($number + 50), album: $title);
        }
        $this->artist('Synthetic name seed', $this->mbid(100));
        $this->listen(' SYNTHETIC  NAME SEED ', album: 'Synthetic OST');
        $taste = app(TasteProfile::class)->load();
        foreach (array_keys($titles) as $number) {
            $this->assertTrue($taste->soundtrackSeed($this->mbid($number + 1)), $titles[$number]);
        }
        foreach (range(50, 53) as $number) {
            $this->assertFalse($taste->soundtrackSeed($this->mbid($number)));
        }
        $this->assertTrue($taste->soundtrackSeed($this->mbid(100)));
    }

    private function freshRow(int $number): array
    {
        return [
            'artist_mbids' => [$this->mbid($number)], 'artist_credit_name' => 'Synthetic artist '.$number,
            'release_group_mbid' => $this->mbid(1000 + $number), 'release_name' => 'Synthetic album '.$number,
            'release_group_primary_type' => 'Album', 'release_group_secondary_types' => [],
            'release_date' => '2026-11-16', 'caa_release_mbid' => null,
        ];
    }

    public function test_candidate_fresh_releases_use_saved_seed_weights_and_cache_artist_tags(): void
    {
        config()->set('discovery.soundtrack_cap', 0);
        $this->listen('Synthetic soundtrack seed', $this->mbid(1), album: 'Synthetic OST');
        $this->listen('Synthetic soundtrack seed two', $this->mbid(2), album: 'Synthetic OST');
        $this->listen('Synthetic regular seed', $this->mbid(3));
        [$lb] = $this->providers([], tags: [$this->mbid(11) => ['tags' => [['name' => 'chiptune']]]]);
        $lb->shouldReceive('userFreshReleases')->andReturn(['payload' => ['releases' => []]]);
        $secondTaggedAlbum = [...$this->freshRow(11), 'release_group_mbid' => $this->mbid(1101)];
        $lb->shouldReceive('freshReleases')->andReturn(['payload' => ['releases' => [
            $this->freshRow(10), $this->freshRow(11), $secondTaggedAlbum, $this->freshRow(12),
        ]]]);
        $batch = $this->batch();
        // All three seed weights matter. The first two alone would miss the soundtrack majority.
        $seeds = [
            ['mbid' => $this->mbid(3), 'name' => 'Synthetic regular seed', 'score' => 8],
            ['mbid' => $this->mbid(1), 'name' => 'Synthetic soundtrack seed', 'score' => 3],
            ['mbid' => $this->mbid(2), 'name' => 'Synthetic soundtrack seed two', 'score' => 3],
        ];
        foreach (range(10, 12) as $number) {
            DiscoveryCandidate::query()->create([
                'artist_mbid' => $this->mbid($number), 'artist_name' => 'Synthetic artist '.$number,
                'score' => 1, 'seeds' => $number === 10 ? $seeds : [], 'batch_id' => $batch->id,
            ]);
        }
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_releases');
        $this->assertSame([$this->mbid(12)], DiscoveryPick::query()->pluck('artist_mbid')->all());
        $this->assertSame(3, $batch->summary['soundtrack_skipped']);
        $this->assertEquals(['release_type' => 0, 'artist_tags' => 2, 'seed_weight' => 1], $batch->summary['soundtrack_rule_hits']);
        $this->assertSame(3, $batch->summary['musicbrainz_calls']);
        Http::assertSentCount(3);
    }

    public function test_artist_tag_failure_is_counted_and_release_type_still_applies(): void
    {
        config()->set('discovery.soundtrack_cap', 0);
        $this->listen('Synthetic seed', $this->mbid(1));
        $this->providers([$this->mbid(1) => $this->radio([10])], [$this->mbid(10)], tagStatus: 404);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame(0, $batch->summary['picks']);
        $this->assertSame(1, $batch->summary['soundtrack_skipped']);
        $this->assertSame(['musicbrainz.artist:404' => 1], $batch->summary['provider_failure_categories']);
        $this->assertSame(1, $batch->summary['provider_failures']);
        $this->assertSame(1, $batch->summary['musicbrainz_calls']);
    }

    public function test_ineligible_candidates_do_not_trigger_artist_tag_lookups(): void
    {
        $this->listen('Synthetic seed', $this->mbid(1));
        [$lb] = $this->providers([$this->mbid(1) => $this->radio([10])]);
        $lb->shouldReceive('topReleaseGroups')->with($this->mbid(10))->andReturn([]);
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_artists');
        $this->assertSame(0, $batch->summary['musicbrainz_calls']);
        Http::assertNothingSent();
    }

    public function test_personal_fresh_releases_are_exempt_and_candidate_cap_keeps_filling(): void
    {
        config()->set('discovery.soundtrack_cap', 1);
        [$lb] = $this->providers([], array_map($this->mbid(...), range(10, 14)));
        $lb->shouldReceive('userFreshReleases')->andReturn(['payload' => ['releases' => array_map($this->freshRow(...), [10, 11, 12])]]);
        $lb->shouldReceive('freshReleases')->andReturn(['payload' => ['releases' => array_map($this->freshRow(...), [12, 13, 14, 15])]]);
        $batch = $this->batch();
        foreach (range(12, 15) as $number) {
            DiscoveryCandidate::query()->create(['artist_mbid' => $this->mbid($number), 'artist_name' => 'Synthetic artist '.$number, 'score' => 1, 'seeds' => [], 'batch_id' => $batch->id]);
        }
        $batch = app(DiscoveryBatchGenerator::class)->generate('new_releases');
        $this->assertSame(array_map($this->mbid(...), [10, 11, 12, 13, 15]), DiscoveryPick::query()->orderBy('position')->pluck('artist_mbid')->all());
        $this->assertSame(1, $batch->summary['soundtrack_picks']);
        $this->assertSame(1, $batch->summary['soundtrack_skipped']);
        $this->assertEquals(['release_type' => 2, 'artist_tags' => 0, 'seed_weight' => 0], $batch->summary['soundtrack_rule_hits']);
        Http::assertSentCount(3);
        foreach ([10, 11, 12] as $number) {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), $this->mbid($number)));
        }
    }
}
