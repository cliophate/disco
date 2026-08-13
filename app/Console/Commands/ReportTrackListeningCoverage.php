<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportTrackListeningCoverage extends Command
{
    protected $signature = 'disco:track-listening-coverage';

    protected $description = 'Report exact provider-specific track listening coverage without exposing listening payloads';

    public function handle(): int
    {
        $plex = DB::table('library.plex_items as tracks')
            ->leftJoin('library.plex_entity_matches as matches', function ($join): void {
                $join->on('matches.plex_item_id', '=', 'tracks.id')
                    ->where('matches.match_scope', 'recording')
                    ->where('matches.status', 'confirmed');
            })
            ->where('tracks.item_type', 'track')->whereNull('tracks.removed_at')
            ->selectRaw('count(DISTINCT tracks.id) as active_tracks')
            ->selectRaw('count(DISTINCT tracks.id) filter (where matches.entity_id is not null) as exact_tracks')
            ->selectRaw('count(DISTINCT tracks.id) filter (where matches.entity_id is null) as unmatched_tracks')
            ->selectRaw('count(DISTINCT tracks.id) filter (where matches.entity_id is not null and tracks.view_count > 0) as counted_tracks')
            ->selectRaw('count(DISTINCT tracks.id) filter (where matches.entity_id is not null and tracks.view_count = 0) as known_zero_tracks')
            ->selectRaw('count(DISTINCT tracks.id) filter (where matches.entity_id is not null and tracks.view_count is null) as unknown_tracks')
            ->selectRaw('max(tracks.last_synced_at) as freshness')
            ->first();
        $duplicates = DB::query()->fromSub(
            DB::table('library.plex_entity_matches as matches')
                ->join('library.plex_items as tracks', 'tracks.id', '=', 'matches.plex_item_id')
                ->where('matches.match_scope', 'recording')->where('matches.status', 'confirmed')
                ->where('tracks.item_type', 'track')->whereNull('tracks.removed_at')
                ->groupBy('matches.entity_id')->selectRaw('matches.entity_id, count(distinct tracks.id) as copies'),
            'recording_copies',
        )->where('copies', '>', 1)->count();
        $listenBrainz = DB::table('activity.listening_event_matches as matches')
            ->join('activity.listening_events as events', 'events.id', '=', 'matches.listening_event_id')
            ->where('matches.status', 'matched')->where('matches.source_present', true)
            ->selectRaw('count(*) as exact_events, count(distinct matches.recording_entity_id) as recordings, min(events.listened_at) as first_listened_at, max(events.listened_at) as last_listened_at')
            ->first();

        $this->table(['Source', 'Active/exact', 'Counted', 'Known zero', 'Unknown/unmatched', 'Freshness'], [[
            'Plex', "{$plex->active_tracks}/{$plex->exact_tracks}", $plex->counted_tracks, $plex->known_zero_tracks,
            "{$plex->unknown_tracks} unknown · {$plex->unmatched_tracks} unmatched", $plex->freshness ?? 'Unavailable',
        ], [
            'ListenBrainz', $listenBrainz->recordings.' recordings', $listenBrainz->exact_events.' events', 'Requires completed full import',
            'Unmatched events excluded', $listenBrainz->last_listened_at ?? 'Unavailable',
        ]]);
        $this->line("{$duplicates} canonical recordings have multiple active exact Plex copies; their displayed count uses the maximum copy count, never a sum.");

        return self::SUCCESS;
    }
}
