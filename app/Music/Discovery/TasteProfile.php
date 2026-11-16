<?php

namespace App\Music\Discovery;

use App\Models\PlexItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TasteProfile
{
    public array $artists = [];

    private array $catalogNames = [];

    private array $namePriorities = [];

    private array $heardArtists = [];

    private array $heardNames = [];

    private array $heardAlbums = [];

    private array $heardAlbumNames = [];

    private array $soundtrackSeeds = [];

    public function __construct(private readonly ArtistPreferencePolicy $preferences) {}

    public function load(): self
    {
        $this->artists = $this->heardArtists = $this->heardNames = $this->heardAlbums = $this->heardAlbumNames = [];
        $this->catalogNames = $this->namePriorities = [];
        $this->soundtrackSeeds = [];
        $byName = [];
        $identifiers = DB::table('catalog.external_identifiers as identifiers')
            ->join('catalog.entities as entities', 'entities.id', '=', 'identifiers.entity_id')
            ->where('identifiers.namespace', 'musicbrainz.artist')->where('identifiers.status', 'active')
            ->where('entities.status', 'active')->select('identifiers.value', 'entities.canonical_name')->cursor();
        foreach ($identifiers as $identifier) {
            $mbid = strtolower($identifier->value);
            $this->catalogNames[$mbid] = $identifier->canonical_name;
            $normalized = Identity::name($identifier->canonical_name);
            // A null entry marks ambiguous catalog names without retaining whole models.
            $byName[$normalized] = array_key_exists($normalized, $byName) ? null : $mbid;
        }
        $rows = DB::table('activity.listening_events as events')
            ->join('source.accounts as accounts', 'accounts.id', '=', 'events.source_account_id')
            ->leftJoin('activity.listen_identities as identities', 'identities.listening_event_id', '=', 'events.id')
            ->leftJoin('activity.listening_event_matches as matches', 'matches.listening_event_id', '=', 'events.id')
            ->where('accounts.status', 'active')->where(fn ($q) => $q->whereNull('matches.id')->orWhere('matches.source_present', true))
            ->select('events.id', 'identities.artist_names', 'events.supplied_artist', 'events.supplied_release', 'events.listened_at', 'events.release_group_mbid', 'identities.artist_mbids', 'identities.release_group_mbid as mapped_group')->lazyById(1000, 'events.id', 'id');
        foreach ($rows as $event) {
            $mbids = Identity::mbids(json_decode($event->artist_mbids ?? '[]', true));
            $name = Identity::name($event->supplied_artist);
            $mappedNames = json_decode($event->artist_names ?? '{}', true);
            if ($mbids === []) {
                $this->heardNames[$name] = ($this->heardNames[$name] ?? 0) + 1;
                if (isset($byName[$name])) {
                    $mbids = [$byName[$name]];
                }
            } else {
                foreach ($mbids as $mbid) {
                    $this->heardArtists[$mbid] = ($this->heardArtists[$mbid] ?? 0) + 1;
                }
            }
            foreach ($mbids as $mbid) {
                $artistName = $mappedNames[$mbid] ?? $this->catalogNames[$mbid] ?? (count($mbids) === 1 ? $event->supplied_artist : null);
                if ($artistName === null) {
                    continue;
                }
                $priority = isset($mappedNames[$mbid]) ? 3 : (isset($this->catalogNames[$mbid]) ? 2 : 1);
                $this->add($mbid, $artistName, $this->weight(CarbonImmutable::parse($event->listened_at)), $priority);
                if (filled($event->supplied_release)) {
                    $this->heardAlbumNames[Identity::album($artistName, $event->supplied_release)] = true;
                }
            }
            $group = $event->mapped_group ?? $event->release_group_mbid;
            if ($group !== null) {
                $this->heardAlbums[$group] = true;
            }
            if (filled($event->supplied_release)) {
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
            $mbid = $byName[Identity::name($artist->title)] ?? null;
            if ($mbid === null) {
                continue;
            }
            $score = max(1, $album->view_count) * $this->weight($album->last_viewed_at ? CarbonImmutable::instance($album->last_viewed_at) : null);
            $plex[$mbid] = ['name' => $artist->title, 'score' => ($plex[$mbid]['score'] ?? 0) + $score];
        }
        foreach ($plex as $mbid => $artist) {
            $this->add($mbid, $this->catalogNames[$mbid] ?? $artist['name'], max(0, $artist['score'] - ($this->artists[$mbid]['score'] ?? 0)), 2);
        }
        uasort($this->artists, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['mbid'], $b['mbid']));
        $this->loadSoundtrackSeeds($byName);

        return $this;
    }

    private function loadSoundtrackSeeds(array $byName): void
    {
        // Aggregate in PostgreSQL instead of retaining another copy of listening history.
        $pattern = '\\m(soundtrack|ost|original[[:space:]]+score|orchestrated|video[[:space:]]+game|game[[:space:]]+music|music[[:space:]]+from|pok[eé]mon|zelda|final[[:space:]]+fantasy|mario|nintendo|vgm)\\M';
        $rows = DB::table('activity.listening_events as events')
            ->join('source.accounts as accounts', 'accounts.id', '=', 'events.source_account_id')
            ->leftJoin('activity.listen_identities as identities', 'identities.listening_event_id', '=', 'events.id')
            ->leftJoin('activity.listening_event_matches as matches', 'matches.listening_event_id', '=', 'events.id')
            ->where('accounts.status', 'active')
            ->where(fn ($q) => $q->whereNull('matches.id')->orWhere('matches.source_present', true))
            ->whereBetween('events.listened_at', [now()->subYears(2), now()])
            ->selectRaw("COALESCE(identities.artist_mbids, '[]'::jsonb) as artist_mbids,
                CASE WHEN COALESCE(identities.artist_mbids, '[]'::jsonb) = '[]'::jsonb THEN events.supplied_artist END as fallback_name,
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE events.supplied_release ~* ? OR EXISTS (
                    SELECT 1 FROM catalog.external_identifiers identifiers
                    JOIN catalog.entities entities ON entities.id = identifiers.entity_id AND entities.status = 'active'
                    JOIN catalog.release_groups groups ON groups.entity_id = identifiers.entity_id
                    WHERE identifiers.namespace = 'musicbrainz.release_group' AND identifiers.status = 'active'
                        AND identifiers.value = identities.release_group_mbid::text
                        AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(groups.secondary_types) secondary_type WHERE lower(secondary_type) = 'soundtrack')
                )) as soundtrack", [$pattern])
            ->groupByRaw('1, 2')->cursor();
        $totals = $soundtracks = [];
        foreach ($rows as $row) {
            $mbids = Identity::mbids(json_decode($row->artist_mbids, true));
            if ($mbids === [] && isset($byName[Identity::name($row->fallback_name)])) {
                $mbids = [$byName[Identity::name($row->fallback_name)]];
            }
            foreach ($mbids as $mbid) {
                if (! isset($this->artists[$mbid])) {
                    continue;
                }
                $totals[$mbid] = ($totals[$mbid] ?? 0) + (int) $row->total;
                $soundtracks[$mbid] = ($soundtracks[$mbid] ?? 0) + (int) $row->soundtrack;
            }
        }
        foreach ($totals as $mbid => $total) {
            if (config('discovery.soundtrack_seed_threshold', 0.5) <= $soundtracks[$mbid] / $total) {
                $this->soundtrackSeeds[$mbid] = true;
            }
        }
    }

    public function soundtrackSeed(string $mbid): bool
    {
        return isset($this->soundtrackSeeds[$mbid]);
    }

    public function weight(?CarbonImmutable $date): float
    {
        $age = $date === null ? INF : max(0, $date->diffInDays(now(), false));

        return 0.1 + ($age <= 365 ? 1 : 0) + ($age <= 90 ? 2 : 0);
    }

    private function add(string $mbid, string $name, float $score, int $priority = 1): void
    {
        if (! $this->preferences->allowsIdentity($mbid, $name)) {
            return;
        }
        $this->artists[$mbid] ??= ['mbid' => $mbid, 'name' => $name, 'score' => 0.0];
        if ($priority > ($this->namePriorities[$mbid] ?? 0)) {
            $this->artists[$mbid]['name'] = $name;
            $this->namePriorities[$mbid] = $priority;
        }
        $this->artists[$mbid]['score'] += $score;
    }

    public function seeds(): array
    {
        return array_values(array_slice($this->artists, 0, (int) config('discovery.seed_limit', 30), true));
    }

    public function artistName(string $mbid): ?string
    {
        return $this->artists[$mbid]['name'] ?? $this->catalogNames[$mbid] ?? null;
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
