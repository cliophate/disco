<?php

namespace Tests\Feature;

use App\Models\PlexItem;
use App\Models\PlexLibrary;
use App\Models\PlexMediaPart;
use App\Models\PlexServer;
use App\Models\User;
use App\Music\Plex\PlexPlaybackSessionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class PlexAudioPlaybackTest extends TestCase
{
    public function test_direct_play_sessions_stream_ranges_and_write_one_scrobble(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $part = $this->part();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/identity') {
                return Http::response('<MediaContainer machineIdentifier="test-machine" />', 200, ['Content-Type' => 'application/xml']);
            }
            if ($path === '/library/parts/501/1700000100/file.flac') {
                if ($request->hasHeader('Range', 'bytes=1-3')) {
                    return Http::response('udi', 206, ['Content-Type' => 'audio/flac', 'Content-Range' => 'bytes 1-3/5', 'Content-Length' => '3', 'Accept-Ranges' => 'bytes']);
                }

                return Http::response('audio', 200, ['Content-Type' => 'audio/flac', 'Content-Length' => '5', 'Accept-Ranges' => 'bytes']);
            }

            return Http::response('', 200, ['Content-Type' => 'application/xml']);
        });

        $this->postJson('/api/v1/playback/sessions', ['media_part_id' => $part->id])->assertUnauthorized();
        $created = $this->actingAs($owner)->postJson('/api/v1/playback/sessions', ['media_part_id' => $part->id])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'stream_url', 'expires_at']]);
        $token = $created->json('data.id');
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $token);
        $this->assertStringNotContainsString('/library/parts/', $created->getContent());
        $this->assertStringNotContainsString('fixture-token', $created->getContent());
        $this->patchJson("/api/v1/playback/sessions/{$token}", ['state' => 'playing', 'position_ms' => 0])->assertConflict();

        $full = $this->get($created->json('data.stream_url'))->assertOk()
            ->assertHeader('Content-Type', 'audio/flac');
        $this->assertStringContainsString('private', (string) $full->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $full->headers->get('Cache-Control'));
        $this->assertSame('audio', $full->streamedContent());
        $partial = $this->withHeader('Range', 'bytes=1-3')->get($created->json('data.stream_url'))
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 1-3/5');
        $this->assertSame('udi', $partial->streamedContent());
        $this->withHeader('Range', 'bytes=0-1,3-4')->get($created->json('data.stream_url'))
            ->assertStatus(416)->assertHeader('Content-Range', 'bytes */5');
        $this->patchJson("/api/v1/playback/sessions/{$token}", ['state' => 'paused', 'position_ms' => 0])
            ->assertOk()->assertJsonPath('data.state', 'paused');

        $cacheKey = 'disco:playback-session:'.hash('sha256', $token);
        $session = Cache::get($cacheKey);
        $session['state'] = 'playing';
        $session['accumulated_ms'] = 3000;
        $session['last_event_at_ms'] = (int) floor(microtime(true) * 1000);
        Cache::put($cacheKey, $session, now()->addHour());
        $this->patchJson("/api/v1/playback/sessions/{$token}", ['state' => 'ended', 'position_ms' => 5000])
            ->assertOk()->assertJsonPath('data.scrobbled', true);
        $this->patchJson("/api/v1/playback/sessions/{$token}", ['state' => 'ended', 'position_ms' => 5000])
            ->assertOk()->assertJsonPath('data.scrobbled', true);

        $scrobbles = Http::recorded()->filter(fn ($pair): bool => parse_url($pair[0]->url(), PHP_URL_PATH) === '/:/scrobble');
        $this->assertCount(1, $scrobbles);
        $this->assertTrue(Http::recorded()->every(fn ($pair): bool => ! str_contains($pair[0]->url(), 'fixture-token')));
        $this->assertTrue(Http::recorded()->every(fn ($pair): bool => parse_url($pair[0]->url(), PHP_URL_PATH) !== '/identity'
            || ! $pair[0]->hasHeader('X-Plex-Token')));

        $other = new User(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'fixture']);
        $other->id = (string) Str::uuid();
        $this->actingAs($other)->get($created->json('data.stream_url'))->assertNotFound();
    }

    public function test_unsupported_original_formats_cannot_create_sessions(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $part = $this->part('dsf', 'dca');

        $this->actingAs($owner)->postJson('/api/v1/playback/sessions', ['media_part_id' => $part->id])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_media_version_change_invalidates_an_existing_session(): void
    {
        $this->preparePostgres();
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => Hash::make('fixture')]);
        $part = $this->part();
        $created = $this->actingAs($owner)->postJson('/api/v1/playback/sessions', ['media_part_id' => $part->id])->assertCreated();
        $part->update(['media_version' => hash('sha256', 'replacement')]);

        $this->get($created->json('data.stream_url'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_stream_slots_are_hard_bounded_globally_and_per_user(): void
    {
        config()->set('services.plex.max_concurrent_streams', 2);
        $user = new User(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'fixture']);
        $user->id = (string) Str::uuid();
        $sessions = app(PlexPlaybackSessionService::class);
        $first = $sessions->acquireStreamLease($user);
        $second = $sessions->acquireStreamLease($user);
        try {
            $this->expectException(TooManyRequestsHttpException::class);
            $sessions->acquireStreamLease($user);
        } finally {
            $second->release();
            $first->release();
        }
    }

    private function part(string $container = 'flac', string $codec = 'flac'): PlexMediaPart
    {
        $server = PlexServer::query()->create(['name' => 'Plex', 'machine_identifier' => 'test-machine', 'machine_identifier_hash' => hash('sha256', 'test-machine'), 'version' => '1', 'last_seen_at' => now()]);
        $library = PlexLibrary::query()->create(['plex_server_id' => $server->id, 'section_key' => '7', 'section_uuid' => 'fixture-library', 'title' => 'Music', 'library_type' => 'artist', 'last_synced_at' => now()]);
        $track = PlexItem::query()->create(['plex_library_id' => $library->id, 'rating_key' => '301', 'parent_rating_key' => '201', 'item_type' => 'track', 'title' => 'Fixture Track', 'duration_ms' => 5000, 'raw_metadata' => [], 'last_synced_at' => now()]);

        return PlexMediaPart::query()->create(['plex_item_id' => $track->id, 'media_id' => '401', 'part_id' => '501', 'part_key' => '/library/parts/501/1700000100/file.flac', 'media_version' => hash('sha256', 'fixture-part'), 'container' => $container, 'audio_codec' => $codec, 'channels' => 2, 'bit_depth' => 24, 'sample_rate_hz' => 96000, 'bitrate_kbps' => 951, 'size_bytes' => 5, 'duration_ms' => 5000, 'last_synced_at' => now()]);
    }

    private function preparePostgres(): void
    {
        if (! extension_loaded('pdo_pgsql') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration test; use compose.test.yaml.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'disco_test') {
            throw new RuntimeException('Refusing to reset a database other than disco_test.');
        }
        foreach (['activity', 'discovery', 'library', 'catalog', 'source', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        config()->set('services.plex.url', 'https://plex.test');
        config()->set('services.plex.token', 'fixture-token');
        config()->set('services.plex.expected_machine_identifier', 'test-machine');
        config()->set('services.plex.expected_library_uuid', 'fixture-library');
        config()->set('services.plex.allow_insecure_http', false);
    }
}
