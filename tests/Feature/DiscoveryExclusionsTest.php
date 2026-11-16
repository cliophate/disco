<?php

namespace Tests\Feature;

use App\Models\DiscoveryPass;
use App\Models\PlexEntityMatch;
use App\Music\Discovery\ArtistPreferencePolicy;
use App\Music\Discovery\DiscoveryExclusions;
use App\Music\Discovery\Identity;
use App\Music\Discovery\OwnedLibrary;
use App\Music\Discovery\TasteProfile;
use Tests\DiscoveryTestCase;

class DiscoveryExclusionsTest extends DiscoveryTestCase
{
    private function exclusions(): DiscoveryExclusions
    {
        return new DiscoveryExclusions(app(TasteProfile::class)->load(), new OwnedLibrary, app(ArtistPreferencePolicy::class));
    }

    public function test_owned_artists_are_excluded_by_mbid_and_normalized_name(): void
    {
        $entity = $this->artist('Owned', $this->mbid(1));
        $item = $this->plex('Owned', '101');
        PlexEntityMatch::query()->create(['plex_item_id' => $item->id, 'entity_id' => $entity->id, 'match_scope' => 'agent', 'status' => 'confirmed', 'method' => 'fixture', 'confidence' => 1]);
        $exclusions = $this->exclusions();
        $this->assertTrue($exclusions->artist($this->mbid(1), 'Different spelling'));
        $this->assertTrue($exclusions->artist($this->mbid(2), '  OWNED  '));
        $this->assertFalse($exclusions->artist($this->mbid(3), 'Unknown'));
    }

    public function test_heard_threshold_uses_ids_and_name_only_history_and_is_configurable(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->listen('By ID', $this->mbid(1));
            $this->listen('By name');
        }
        $exclusions = $this->exclusions();
        $this->assertTrue($exclusions->artist($this->mbid(1), 'Alias'));
        $this->assertTrue($exclusions->artist($this->mbid(2), 'BY NAME'));
        config()->set('discovery.lifetime_listen_threshold', 6);
        $this->assertFalse($this->exclusions()->artist($this->mbid(1), 'By ID'));
    }

    public function test_artist_passes_suppress_both_ids_and_names(): void
    {
        DiscoveryPass::query()->create(['scope' => 'artist', 'mbid' => $this->mbid(1), 'normalized_name' => 'passed artist']);
        $exclusions = $this->exclusions();
        $this->assertTrue($exclusions->artist($this->mbid(1), 'Alias'));
        $this->assertTrue($exclusions->artist($this->mbid(2), 'Passed artist'));
    }

    public function test_recent_artist_cooldown_expires_after_configured_weeks(): void
    {
        $pick = $this->pick();
        $this->assertTrue($this->exclusions()->artist($pick->artist_mbid, 'Alias'));
        $this->assertTrue($this->exclusions()->artist($this->mbid(3), 'CANDIDATE'));
        $pick->forceFill(['created_at' => now()->subWeeks(13)])->save();
        $this->assertFalse($this->exclusions()->artist($pick->artist_mbid, $pick->artist_name));
    }

    public function test_special_artist_names_and_ids_are_excluded(): void
    {
        $exclusions = $this->exclusions();
        $this->assertTrue($exclusions->artist('89ad4ac3-39f7-470e-963a-56509c546377', 'Alias'));
        $this->assertTrue($exclusions->artist($this->mbid(1), 'Various Artists'));
        $this->assertTrue($exclusions->artist($this->mbid(2), '[unknown]'));
    }

    public function test_owned_passed_and_previously_picked_albums_cannot_repeat(): void
    {
        $this->plex('Artist', '101');
        $this->plex('Owned album', '201', 'album', '101');
        DiscoveryPass::query()->create(['scope' => 'album', 'mbid' => $this->mbid(21), 'normalized_name' => Identity::album('Artist', 'Passed album')]);
        $pick = $this->pick();
        $exclusions = $this->exclusions();
        $this->assertTrue($exclusions->album($this->mbid(11), ' ARTIST ', 'OWNED ALBUM'));
        $this->assertTrue($exclusions->album($this->mbid(21), 'Alias', 'Title'));
        $this->assertTrue($exclusions->album($this->mbid(22), 'Artist', 'Passed album'));
        $this->assertTrue($exclusions->album($pick->release_group_mbid, $pick->artist_name, $pick->title));
        $this->assertFalse($exclusions->album($this->mbid(99), 'Artist', 'Unheard'));
    }
}
