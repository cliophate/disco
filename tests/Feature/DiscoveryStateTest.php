<?php

namespace Tests\Feature;

use App\Models\CatalogEntity;
use App\Models\DiscoveryPass;
use App\Models\ExternalIdentifier;
use App\Models\PlexEntityMatch;
use App\Models\ReleaseGroup;
use App\Music\Discovery\PickStateReconciler;
use App\Music\Plex\PlexSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\DiscoveryTestCase;

class DiscoveryStateTest extends DiscoveryTestCase
{
    public function test_new_to_in_plex_to_played_is_reconciled_after_each_sync_by_names(): void
    {
        $pick = $this->pick(extra: ['artist_name' => 'Synthetic Artist', 'title' => 'Synthetic Album']);
        config()->set('services.plex.url', 'https://plex.test');
        config()->set('services.plex.token', 'fixture-token');
        config()->set('services.plex.expected_machine_identifier', 'fixture-server');
        config()->set('services.plex.expected_library_uuid', 'fixture-library');
        $played = false;
        Http::fake(function (Request $request) use (&$played) {
            $xml = match (parse_url($request->url(), PHP_URL_PATH)) {
                '/identity' => '<MediaContainer machineIdentifier="fixture-server" />',
                '/library/sections' => '<MediaContainer><Directory key="7" uuid="fixture-library" title="Music" type="artist" /></MediaContainer>',
                default => match ((int) $request['type']) {
                    8 => '<MediaContainer size="1" totalSize="1"><Directory ratingKey="101" title="Synthetic Artist" /></MediaContainer>',
                    9 => '<MediaContainer size="1" totalSize="1"><Directory ratingKey="201" parentRatingKey="101" title="Synthetic Album" viewCount="'.($played ? 1 : 0).'" /></MediaContainer>',
                    default => '<MediaContainer size="0" totalSize="0" />',
                },
            };

            return Http::response($xml, 200, ['Content-Type' => 'application/xml']);
        });
        app(PlexSyncService::class)->sync();
        $this->assertSame('in_plex', $pick->fresh()->state);
        $this->assertNotNull($pick->fresh()->in_plex_at);
        $played = true;
        app(PlexSyncService::class)->sync();
        $this->assertSame('played', $pick->fresh()->state);
        $this->assertNotNull($pick->fresh()->played_at);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_mbid_ownership_works_when_names_differ_and_states_never_regress(): void
    {
        $pick = $this->pick();
        $album = $this->plex('Other title', '201', 'album');
        $group = CatalogEntity::query()->create(['kind' => 'release_group', 'status' => 'active', 'canonical_name' => 'Other', 'sort_name' => 'Other']);
        ReleaseGroup::query()->create(['entity_id' => $group->id]);
        ExternalIdentifier::query()->create(['entity_id' => $group->id, 'namespace' => 'musicbrainz.release_group', 'value' => $pick->release_group_mbid, 'status' => 'active']);
        PlexEntityMatch::query()->create(['plex_item_id' => $album->id, 'entity_id' => $group->id, 'match_scope' => 'release_group', 'status' => 'confirmed', 'method' => 'fixture', 'confidence' => 1]);
        app(PickStateReconciler::class)->reconcile();
        $this->assertSame('in_plex', $pick->fresh()->state);
        $this->listen('Alias', album: 'Other', groupMbid: $pick->release_group_mbid);
        app(PickStateReconciler::class)->reconcile();
        $this->assertSame('played', $pick->fresh()->state);
        $album->update(['removed_at' => now()]);
        app(PickStateReconciler::class)->reconcile();
        $this->assertSame('played', $pick->fresh()->state);
    }

    public function test_matching_listens_can_mark_a_pick_played_before_the_next_plex_sync(): void
    {
        $pick = $this->pick();
        $this->listen(' CANDIDATE ', album: ' candidate album ');
        app(PickStateReconciler::class)->reconcile();
        $this->assertSame('played', $pick->fresh()->state);
    }

    public function test_pass_is_artist_scoped_for_new_artists_and_album_scoped_elsewhere(): void
    {
        $artistPick = $this->pick();
        $albumPick = $this->pick($this->batch('more'), ['artist_mbid' => $this->mbid(3), 'release_group_mbid' => $this->mbid(30)]);
        $this->actingAs($this->owner)->postJson('/api/v1/discovery/picks/'.$artistPick->id.'/pass')->assertNoContent();
        $this->postJson('/api/v1/discovery/picks/'.$artistPick->id.'/pass')->assertNoContent();
        $this->postJson('/api/v1/discovery/picks/'.$albumPick->id.'/pass')->assertNoContent();
        $this->assertSame(['album', 'artist'], DiscoveryPass::query()->orderBy('scope')->pluck('scope')->all());
        $this->assertNotNull($artistPick->fresh()->passed_at);
        $this->assertNotNull($albumPick->fresh()->passed_at);
        Http::assertNothingSent();
    }

    public function test_page_reads_local_state_with_current_then_earlier_and_explicit_stale_state(): void
    {
        $earlier = $this->pick();
        $current = $this->pick($this->batch('new_artists', '2026-11-16'), ['release_group_mbid' => $this->mbid(21)]);
        $response = $this->actingAs($this->owner)->getJson('/api/v1/discovery')->assertOk();
        $response->assertJsonPath('data.0.picks.0.id', $current->id)->assertJsonPath('data.0.earlier.0.id', $earlier->id);
        $this->travel(8)->days();
        $this->getJson('/api/v1/discovery')->assertJsonPath('data.0.stale', true);
        $this->getJson('/api/v1/admin/overview')->assertOk()->assertJsonCount(2, 'data.providers');
        Http::assertNothingSent();
    }

    public function test_authentication_and_removed_routes(): void
    {
        $this->getJson('/api/v1/discovery')->assertUnauthorized();
        $this->actingAs($this->owner);
        foreach (['/api/v1/home', '/api/v1/albums', '/api/v1/search', '/api/v1/playback/sessions', '/api/v1/upcoming', '/api/v1/admin/operations', '/discover', '/beyond', '/library/albums'] as $url) {
            $this->getJson($url)->assertNotFound();
        }
    }
}
