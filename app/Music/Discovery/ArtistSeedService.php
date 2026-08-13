<?php

namespace App\Music\Discovery;

use App\Models\ArtistFollow;
use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Music\CanonicalEntityResolver;
use Illuminate\Support\Facades\DB;

class ArtistSeedService
{
    public function __construct(
        private readonly CanonicalEntityResolver $resolver,
        private readonly ArtistPreferencePolicy $preferences,
    ) {}

    /** @return array<string, array{explicit:bool,implicit:bool,seed:bool}> */
    public function forUser(string $userId): array
    {
        $seeds = [];
        ArtistFollow::query()->where('user_id', $userId)->pluck('artist_entity_id')->each(function (string $id) use (&$seeds): void {
            $canonical = $this->resolver->resolve($id, 'agent');
            if ($canonical !== null && $this->preferences->allows($canonical)) {
                $seeds[$canonical->id] = ['explicit' => true, 'implicit' => false, 'seed' => true];
            }
        });
        DB::table('library.plex_entity_matches as matches')
            ->join('library.plex_items as artists', 'artists.id', '=', 'matches.plex_item_id')
            ->join('library.plex_items as albums', function ($join): void {
                $join->on('albums.plex_library_id', '=', 'artists.plex_library_id')->on('albums.parent_rating_key', '=', 'artists.rating_key');
            })
            ->join('library.holdings as holdings', 'holdings.plex_album_item_id', '=', 'albums.id')
            ->where('matches.match_scope', 'agent')->where('matches.status', 'confirmed')
            ->where('artists.item_type', 'artist')->whereNull('artists.removed_at')
            ->where('albums.item_type', 'album')->whereNull('albums.removed_at')
            ->distinct()->pluck('matches.entity_id')->each(function (string $id) use (&$seeds): void {
                $canonical = $this->resolver->resolve($id, 'agent');
                if ($canonical !== null && $this->preferences->allows($canonical)) {
                    $seeds[$canonical->id] = ['explicit' => $seeds[$canonical->id]['explicit'] ?? false, 'implicit' => true, 'seed' => true];
                }
            });

        return $seeds;
    }

    /** @return array{explicit:bool,implicit:bool,seed:bool} */
    public function state(string $userId, string $artistId): array
    {
        return $this->forUser($userId)[$artistId] ?? ['explicit' => false, 'implicit' => false, 'seed' => false];
    }

    /** @return array<string, array{explicit:bool,implicit:bool}> */
    public function exactMbidStates(string $userId): array
    {
        $states = [];
        ArtistFollow::query()->where('user_id', $userId)->pluck('artist_entity_id')->each(function (string $id) use (&$states): void {
            $canonical = $this->resolver->resolve($id, 'agent');
            if ($canonical !== null && $this->preferences->allows($canonical)) {
                $states[$canonical->id] = ['explicit' => true, 'implicit' => false];
            }
        });
        DB::table('library.plex_entity_matches as matches')
            ->join('library.plex_items as artists', 'artists.id', '=', 'matches.plex_item_id')
            ->join('library.plex_items as albums', function ($join): void {
                $join->on('albums.plex_library_id', '=', 'artists.plex_library_id')->on('albums.parent_rating_key', '=', 'artists.rating_key');
            })
            ->join('library.holdings as holdings', 'holdings.plex_album_item_id', '=', 'albums.id')
            ->where('matches.match_scope', 'agent')->where('matches.status', 'confirmed')
            ->where('artists.item_type', 'artist')->whereNull('artists.removed_at')
            ->where('albums.item_type', 'album')->whereNull('albums.removed_at')
            ->distinct()->pluck('matches.entity_id')->each(function (string $id) use (&$states): void {
                $canonical = $this->resolver->resolve($id, 'agent');
                if ($canonical !== null && $this->preferences->allows($canonical)) {
                    $states[$canonical->id] = [
                        'explicit' => $states[$canonical->id]['explicit'] ?? false,
                        'implicit' => true,
                    ];
                }
            });

        $mbids = [];
        for ($depth = 0; $depth < 10; $depth++) {
            $aliases = CatalogEntity::query()->where('kind', 'agent')->where('status', 'redirected')
                ->whereIn('redirect_entity_id', array_keys($states))->whereNotIn('id', array_keys($states))
                ->get(['id', 'redirect_entity_id']);
            if ($aliases->isEmpty()) {
                break;
            }
            foreach ($aliases as $alias) {
                $states[$alias->id] = $states[$alias->redirect_entity_id];
            }
        }
        ExternalIdentifier::query()->whereIn('entity_id', array_keys($states))
            ->where('namespace', 'musicbrainz.artist')->where('status', 'active')
            ->get(['entity_id', 'value'])->each(function (ExternalIdentifier $identifier) use (&$mbids, $states): void {
                if (! isset($states[$identifier->entity_id])) {
                    return;
                }
                $key = strtolower($identifier->value);
                $mbids[$key] = [
                    'explicit' => ($mbids[$key]['explicit'] ?? false) || $states[$identifier->entity_id]['explicit'],
                    'implicit' => ($mbids[$key]['implicit'] ?? false) || $states[$identifier->entity_id]['implicit'],
                ];
            });

        return $mbids;
    }
}
