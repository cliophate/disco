<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.manual_operations DROP CONSTRAINT manual_operations_key_check');
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_key_check CHECK (operation_key IN ('plex.sync', 'listenbrainz.import', 'listenbrainz.recommendations', 'musicbrainz.enrich', 'discogs.enrich', 'artwork.discographies', 'catalog.enrich', 'upcoming.refresh', 'notifications.deliver'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM app.manual_operations WHERE operation_key = 'catalog.enrich'");
        DB::statement('ALTER TABLE app.manual_operations DROP CONSTRAINT manual_operations_key_check');
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_key_check CHECK (operation_key IN ('plex.sync', 'listenbrainz.import', 'listenbrainz.recommendations', 'musicbrainz.enrich', 'discogs.enrich', 'artwork.discographies', 'upcoming.refresh', 'notifications.deliver'))");
    }
};
