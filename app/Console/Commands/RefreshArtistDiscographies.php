<?php

namespace App\Console\Commands;

use App\Models\ArtistDiscographyGeneration;
use App\Models\CatalogEntity;
use App\Music\Discovery\ArtistDiscographyRefresher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RefreshArtistDiscographies extends Command
{
    protected $signature = 'disco:artist-discographies {--artist= : Exact canonical artist entity UUID} {--limit=5 : Maximum artists to refresh}';

    protected $description = 'Cache exact official MusicBrainz discographies for canonical artists';

    public function handle(ArtistDiscographyRefresher $refresher): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 25) {
            throw new RuntimeException('Discography refresh limit must be between 1 and 25.');
        }
        $artist = $this->option('artist');
        if ($artist !== null && ! Str::isUuid((string) $artist)) {
            throw new RuntimeException('Artist must be an exact entity UUID.');
        }
        $ids = $artist !== null
            ? collect([(string) $artist])
            : CatalogEntity::query()
                ->select('catalog.entities.id')
                ->join('catalog.agents', 'catalog.agents.entity_id', '=', 'catalog.entities.id')
                ->join('catalog.external_identifiers', function ($join): void {
                    $join->on('catalog.external_identifiers.entity_id', '=', 'catalog.entities.id')
                        ->where('catalog.external_identifiers.namespace', 'musicbrainz.artist')
                        ->where('catalog.external_identifiers.status', 'active');
                })
                ->leftJoinSub(
                    ArtistDiscographyGeneration::query()->select(
                        'artist_entity_id',
                        DB::raw('max(generated_at) as refreshed_at'),
                        DB::raw('max(expires_at) as refresh_due_at'),
                    )->groupBy('artist_entity_id'),
                    'discographies',
                    'discographies.artist_entity_id',
                    '=',
                    'catalog.entities.id',
                )
                ->leftJoin('discovery.artist_follows', 'discovery.artist_follows.artist_entity_id', '=', 'catalog.entities.id')
                ->leftJoin('library.plex_entity_matches', function ($join): void {
                    $join->on('library.plex_entity_matches.entity_id', '=', 'catalog.entities.id')
                        ->where('library.plex_entity_matches.match_scope', 'agent')
                        ->whereIn('library.plex_entity_matches.status', ['confirmed', 'candidate']);
                })
                ->leftJoin('library.plex_items', function ($join): void {
                    $join->on('library.plex_items.id', '=', 'library.plex_entity_matches.plex_item_id')
                        ->where('library.plex_items.item_type', 'artist')->whereNull('library.plex_items.removed_at');
                })
                ->where('catalog.entities.kind', 'agent')
                ->where('catalog.entities.status', 'active')
                ->where(fn ($query) => $query->whereNotNull('discovery.artist_follows.id')->orWhereNotNull('library.plex_items.id'))
                ->where(fn ($query) => $query->whereNull('discographies.refresh_due_at')->orWhere('discographies.refresh_due_at', '<=', now()))
                ->groupBy('catalog.entities.id', 'discographies.refreshed_at', 'discographies.refresh_due_at')
                ->havingRaw('count(distinct catalog.external_identifiers.id) = 1')
                ->orderByRaw('discographies.refreshed_at asc nulls first')
                ->limit($limit * 5)
                ->pluck('catalog.entities.id');

        $rows = [];
        $failed = 0;
        foreach ($ids as $id) {
            if (count($rows) >= $limit) {
                break;
            }
            try {
                $result = $refresher->refresh($id);
                $rows[] = [$result['artist_id'], $result['items'], $result['pages'], $result['truncated'] ? 'yes' : 'no'];
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("{$id}: {$exception->getMessage()}");
            }
        }
        $this->table(['Artist', 'Official groups', 'Pages', 'Truncated'], $rows);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
