<?php

namespace App\Music\Discovery;

use App\Models\PlexItem;

class OwnedLibrary
{
    public array $artists = [];

    public array $albums = [];

    public function __construct()
    {
        $items = PlexItem::query()->whereNull('removed_at')->whereIn('item_type', ['artist', 'album'])
            ->with('matches.entity.identifiers')->get();
        $artists = $items->where('item_type', 'artist')->keyBy(fn ($item) => $item->plex_library_id.':'.$item->rating_key);
        foreach ($artists as $artist) {
            $mbids = $artist->matches->filter(fn ($match) => in_array($match->status, ['candidate', 'confirmed'], true))
                ->flatMap(fn ($match) => $match->entity?->identifiers ?? [])
                ->where('namespace', 'musicbrainz.artist')->where('status', 'active')->pluck('value')->all();
            $this->artists[] = ['name' => $artist->title, 'mbids' => Identity::mbids($mbids)];
        }
        foreach ($items->where('item_type', 'album') as $album) {
            $artist = $artists->get($album->plex_library_id.':'.$album->parent_rating_key);
            $artistName = $artist?->title ?? data_get($album->raw_metadata, 'parentTitle', '');
            $mbids = $album->matches->filter(fn ($match) => in_array($match->status, ['candidate', 'confirmed'], true))
                ->flatMap(fn ($match) => $match->entity?->identifiers ?? [])
                ->where('namespace', 'musicbrainz.release_group')->where('status', 'active')->pluck('value')->all();
            $this->albums[] = [
                'mbids' => Identity::mbids($mbids), 'name' => Identity::album($artistName, $album->title),
                'played' => $album->view_count > 0 || $album->last_viewed_at !== null,
            ];
        }
    }

    public function hasArtist(string $mbid, string $name): bool
    {
        return collect($this->artists)->contains(fn ($artist) => in_array($mbid, $artist['mbids'], true)
            || Identity::name($name) === Identity::name($artist['name']));
    }

    public function album(string $mbid, string $artist, string $title): ?array
    {
        return collect($this->albums)->first(fn ($album) => in_array($mbid, $album['mbids'], true)
            || $album['name'] === Identity::album($artist, $title));
    }
}
