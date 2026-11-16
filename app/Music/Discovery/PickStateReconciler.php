<?php

namespace App\Music\Discovery;

use App\Models\DiscoveryPick;

class PickStateReconciler
{
    public function __construct(private readonly TasteProfile $taste) {}

    public function reconcile(): int
    {
        $owned = new OwnedLibrary;
        $this->taste->load();
        $changed = 0;
        foreach (DiscoveryPick::query()->where('state', '!=', 'played')->lazyById(250) as $pick) {
            $album = $owned->album($pick->release_group_mbid, $pick->artist_name, $pick->title);
            $heard = $this->taste->heardAlbum($pick->release_group_mbid, $pick->artist_name, $pick->title);
            $updates = [];
            if ($album !== null && $pick->in_plex_at === null) {
                $updates = ['in_plex_at' => now(), 'state' => 'in_plex'];
            }
            if (($album['played'] ?? false) || $heard) {
                $updates = [...$updates, 'state' => 'played', 'played_at' => now()];
            }
            if ($updates !== []) {
                $pick->update($updates);
                $changed++;
            }
        }

        return $changed;
    }
}
