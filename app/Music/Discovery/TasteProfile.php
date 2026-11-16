<?php

namespace App\Music\Discovery;

use App\Models\ExternalIdentifier;
use App\Models\PlexItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TasteProfile
{
    public array $artists = [];

    private array $heardArtists = [];

    private array $heardNames = [];

    private array $heardAlbums = [];

    private array $heardAlbumNames = [];

    public function __construct(private readonly ArtistPreferencePolicy $preferences) {}

    public function load(): self
    {
        $this->artists = $this->heardArtists = $this->heardNames = $this->heardAlbums = $this->heardAlbumNames = [];
        $identifiers = ExternalIdentifier::query()->where('namespace', 'musicbrainz.artist')->where('status', 'active')
            ->with('entity')->get();
        $byName = $identifiers->groupBy(fn ($id) => Identity::name($id->entity?->canonical_name));
        $rows = DB::table('activity.listening_events as events')
            ->join('source.accounts as accounts', 'accounts.id', '=', 'events.source_account_id')
            ->leftJoin('activity.listen_identities as identities', 'identities.listening_event_id', '=', 'events.id')
            ->leftJoin('activity.listening_event_matches as matches', 'matches.listening_event_id', '=', 'events.id')
            ->where('accounts.status', 'active')->where(fn ($q) => $q->whereNull('matches.id')->orWhere('matches.source_present', true))
            ->select('events.supplied_artist', 'events.supplied_release', 'events.listened_at', 'events.release_group_mbid', 'identities.artist_mbids', 'identities.release_group_mbid as mapped_group')->cursor();
        foreach ($rows as $event) {
            $mbids = Identity::mbids(json_decode($event->artist_mbids ?? '[]', true));
            $name = Identity::name($event->supplied_artist);
            if ($mbids === []) {
                $this->heardNames[$name] = ($this->heardNames[$name] ?? 0) + 1;
                $matches = $byName->get($name);
                if ($matches?->count() === 1) {
                    $mbids = [$matches->first()->value];
                }
            } else {
                foreach ($mbids as $mbid) {
                    $this->heardArtists[$mbid] = ($this->heardArtists[$mbid] ?? 0) + 1;
                }
            }
            foreach ($mbids as $mbid) {
                $this->add($mbid, $event->supplied_artist, $this->weight(CarbonImmutable::parse($event->listened_at)));
            }
            $group = $event->mapped_group ?? $event->release_group_mbid;
            if ($group !== null) {
                $this->heardAlbums[$group] = true;
            } elseif (filled($event->supplied_release)) {
                $this->heardAlbumNames[Identity::album($event->supplied_artist, $event->supplied_release)] = true;
            }
        }
        // Plex scrobbles usually reach ListenBrainz too. Use the larger contribution, not their sum.
        $plex = [];
        $artists = PlexItem::query()->where('item_type', 'artist')->whereNull('removed_at')->get()
            ->keyBy(fn ($item) => $item->plex_library_id.':'.$item->rating_key);
        foreach (PlexItem::query()->where('item_type', 'album')->whereNull('removed_at')->get() as $album) {
            $artist = $artists->get($album->plex_library_id.':'.$album->parent_rating_key);
            if ($artist === null || ($album->view_count < 1 && $album->last_viewed_at === null)) {
                continue;
            }
            $matches = $byName->get(Identity::name($artist->title));
            if ($matches?->count() !== 1) {
                continue;
            }
            $mbid = $matches->first()->value;
            $score = max(1, $album->view_count) * $this->weight($album->last_viewed_at ? CarbonImmutable::instance($album->last_viewed_at) : null);
            $plex[$mbid] = ['name' => $artist->title, 'score' => ($plex[$mbid]['score'] ?? 0) + $score];
        }
        foreach ($plex as $mbid => $artist) {
            $this->add($mbid, $artist['name'], max(0, $artist['score'] - ($this->artists[$mbid]['score'] ?? 0)));
        }
        uasort($this->artists, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['mbid'], $b['mbid']));

        return $this;
    }

    public function weight(?CarbonImmutable $date): float
    {
        $age = $date === null ? INF : max(0, $date->diffInDays(now(), false));

        return 0.1 + ($age <= 365 ? 1 : 0) + ($age <= 90 ? 2 : 0);
    }

    private function add(string $mbid, string $name, float $score): void
    {
        if (! $this->preferences->allowsIdentity($mbid, $name)) {
            return;
        }
        $this->artists[$mbid] ??= ['mbid' => $mbid, 'name' => $name, 'score' => 0.0];
        $this->artists[$mbid]['score'] += $score;
    }

    public function seeds(): array
    {
        return array_values(array_slice($this->artists, 0, (int) config('discovery.seed_limit', 30), true));
    }

    public function lifetimeListens(string $mbid, string $name): int
    {
        return ($this->heardArtists[$mbid] ?? 0) + ($this->heardNames[Identity::name($name)] ?? 0);
    }

    public function heardAlbum(string $mbid, string $artist, string $title): bool
    {
        return isset($this->heardAlbums[$mbid]) || isset($this->heardAlbumNames[Identity::album($artist, $title)]);
    }
}
