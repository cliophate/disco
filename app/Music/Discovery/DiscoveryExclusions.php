<?php

namespace App\Music\Discovery;

use App\Models\DiscoveryPass;
use App\Models\DiscoveryPick;

class DiscoveryExclusions
{
    private array $passes;

    private array $recentArtists;

    private array $recentNames;

    private array $pickedAlbums;

    public function __construct(
        private readonly TasteProfile $taste,
        private readonly OwnedLibrary $owned,
        private readonly ArtistPreferencePolicy $preferences,
    ) {
        $this->passes = DiscoveryPass::query()->get()->toArray();
        $recent = DiscoveryPick::query()->where('created_at', '>=', now()->subWeeks(config('discovery.repeat_weeks', 12)))
            ->get(['artist_mbid', 'artist_name']);
        $this->recentArtists = $recent->pluck('artist_mbid')->flip()->all();
        $this->recentNames = $recent->map(fn ($pick) => Identity::name($pick->artist_name))->flip()->all();
        $this->pickedAlbums = DiscoveryPick::query()->pluck('release_group_mbid')->flip()->all();
    }

    public function artist(string $mbid, string $name): bool
    {
        return ! $this->preferences->allowsIdentity($mbid, $name)
            || $this->owned->hasArtist($mbid, $name)
            || $this->taste->lifetimeListens($mbid, $name) > config('discovery.lifetime_listen_threshold', 5)
            || $this->passed('artist', $mbid, Identity::name($name))
            || isset($this->recentArtists[$mbid]) || isset($this->recentNames[Identity::name($name)]);
    }

    public function album(string $mbid, string $artist, string $title, ?string $artistMbid = null): bool
    {
        return $this->passed('artist', $artistMbid ?? '', Identity::name($artist))
            || $this->owned->album($mbid, $artist, $title) !== null
            || $this->passed('album', $mbid, Identity::album($artist, $title))
            || isset($this->pickedAlbums[$mbid])
            || $this->taste->heardAlbum($mbid, $artist, $title);
    }

    private function passed(string $scope, string $mbid, string $name): bool
    {
        return collect($this->passes)->contains(fn ($pass) => $pass['scope'] === $scope
            && ($pass['mbid'] === $mbid || $pass['normalized_name'] === $name));
    }
}
