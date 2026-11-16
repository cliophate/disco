<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'discovery.upcoming_notification_deliveries', 'discovery.upcoming_release_notifications',
            'discovery.upcoming_release_items', 'discovery.upcoming_release_generations',
            'discovery.artist_discography_items', 'discovery.artist_discography_generations',
            'discovery.recommendation_impressions', 'discovery.feedback_revisions',
            'discovery.recommendation_feedback', 'discovery.recommendation_evidence',
            'discovery.home_editions', 'discovery.recommendation_items', 'discovery.recommendation_runs', 'discovery.album_list_items', 'discovery.artist_follows',
            'discovery.editorial_items', 'catalog.credit_edges', 'catalog.entity_narratives',
            'source.discogs_enrichment_states', 'app.manual_operations', 'library.plex_media_parts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('app.provider_credentials')->whereIn('provider', ['discogs', 'gotify', 'theaudiodb'])->delete();
        DB::table('source.providers')->whereIn('slug', ['discogs', 'gotify', 'theaudiodb', 'wikimedia', 'pitchfork'])->update(['enabled' => false]);
        DB::table('source.providers')->where('slug', 'plex')->update(['policy' => json_encode(['storage' => 'private', 'connector' => 'read_only'])]);
    }

    public function down(): void
    {
        throw new RuntimeException('The retired discovery data cannot be restored by rollback. Restore a pre-upgrade backup.');
    }
};
