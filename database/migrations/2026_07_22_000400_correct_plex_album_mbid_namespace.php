<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release_group')
            ->update(['namespace' => 'musicbrainz.release']);
    }

    public function down(): void
    {
        DB::table('catalog.external_identifiers')
            ->where('namespace', 'musicbrainz.release')
            ->update(['namespace' => 'musicbrainz.release_group']);
    }
};
