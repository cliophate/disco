<?php

namespace Tests\Feature;

use App\Models\ListeningEvent;
use App\Music\Discovery\TasteProfile;
use App\Music\ListenBrainz\ListenBrainzImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\DiscoveryTestCase;

class DiscoveryImportTest extends DiscoveryTestCase
{
    private array $providerListens = [];

    private bool $faked = false;

    private function fakeListens(array $listens): void
    {
        $this->providerListens = $listens;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'validate-token')) {
                return Http::response(['valid' => true, 'user_name' => 'fixture-listener'], 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(['payload' => ['user_id' => 'fixture-listener', 'listens' => $this->providerListens]], 200, ['Content-Type' => 'application/json']);
        });
    }

    private function row(int $timestamp, array $mapping = [], array $additional = []): array
    {
        return ['listened_at' => $timestamp, 'track_metadata' => ['artist_name' => 'Synthetic Artist', 'track_name' => 'Synthetic Track', 'release_name' => 'Synthetic Album', 'mbid_mapping' => $mapping, 'additional_info' => $additional]];
    }

    public function test_default_overlap_catches_late_batches_and_deduplicates(): void
    {
        $latest = now()->timestamp;
        $this->account->update(['cursor' => ['latest_listened_at' => $latest]]);
        $this->assertGreaterThanOrEqual(172800, config('services.listenbrainz.overlap_seconds'));
        $this->fakeListens([$this->row($latest - 7200)]);
        $first = app(ListenBrainzImporter::class)->import();
        $this->assertSame(1, $first['inserted']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/listens') && (int) $request['min_ts'] === $latest - 172800);
        $second = app(ListenBrainzImporter::class)->import();
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, ListeningEvent::query()->count());
        config()->set('services.listenbrainz.overlap_seconds', 259200);
        app(ListenBrainzImporter::class)->import();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/listens') && (int) $request['min_ts'] === $latest - 259200);
    }

    public function test_full_reimport_backfills_mapped_identity_without_mutating_the_event(): void
    {
        $timestamp = now()->subDay()->timestamp;
        $this->fakeListens([$this->row($timestamp)]);
        app(ListenBrainzImporter::class)->import(full: true);
        $event = ListeningEvent::query()->firstOrFail();
        $this->assertNull($event->release_group_mbid);
        $this->fakeListens([$this->row($timestamp, ['artist_mbids' => [$this->mbid(1)], 'release_group_mbid' => $this->mbid(2)])]);
        app(ListenBrainzImporter::class)->import(full: true);
        $identity = DB::table('activity.listen_identities')->where('listening_event_id', $event->id)->first();
        $this->assertSame([$this->mbid(1)], json_decode($identity->artist_mbids, true));
        $this->assertSame($this->mbid(2), $identity->release_group_mbid);
        $this->assertNull($event->fresh()->release_group_mbid);
        $this->assertSame(1, ListeningEvent::query()->count());
        $this->assertTrue(app(TasteProfile::class)->load()->heardAlbum($this->mbid(2), 'Alias', 'Alias'));
        $this->expectException(QueryException::class);
        DB::table('activity.listening_events')->where('id', $event->id)->update(['supplied_artist' => 'Mutated']);
    }

    public function test_submitted_artist_and_release_group_ids_are_persisted_and_full_removals_do_not_count(): void
    {
        $this->fakeListens([$this->row(now()->timestamp, [], ['artist_mbids' => [$this->mbid(1)], 'release_group_mbid' => $this->mbid(2)])]);
        app(ListenBrainzImporter::class)->import(full: true);
        $this->assertSame([$this->mbid(1)], array_column(app(TasteProfile::class)->load()->seeds(), 'mbid'));
        $this->fakeListens([]);
        app(ListenBrainzImporter::class)->import(full: true);
        $this->assertSame([], app(TasteProfile::class)->load()->seeds());
        $this->assertSame(1, ListeningEvent::query()->count());
    }

    public function test_import_timestamps_are_utc_even_when_legacy_app_timezone_is_set(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
        $this->fakeListens([$this->row(now()->timestamp)]);
        app(ListenBrainzImporter::class)->import();
        $stored = DB::table('activity.listen_import_runs')->value('started_at');
        $this->assertSame(now()->timestamp, CarbonImmutable::parse($stored)->timestamp);
    }
}
