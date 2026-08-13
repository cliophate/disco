<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library.plex_item_artworks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_item_id')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('observed_thumb_hash', 64)->nullable();
            $table->string('ingested_thumb_hash', 64)->nullable();
            $table->string('content_sha256', 64)->nullable()->index();
            $table->string('storage_key')->nullable()->index();
            $table->string('mime_type', 32)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('ingested_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE library.plex_item_artworks ADD CONSTRAINT plex_item_artworks_item_fk FOREIGN KEY (plex_item_id) REFERENCES library.plex_items(id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE library.plex_item_artworks ADD CONSTRAINT plex_item_artworks_status_check CHECK (status IN ('pending', 'ready', 'stale', 'failed', 'missing'))");
        DB::statement('ALTER TABLE library.plex_item_artworks ADD CONSTRAINT plex_item_artworks_dimensions_check CHECK ((width IS NULL AND height IS NULL) OR (width > 0 AND height > 0 AND width::bigint * height::bigint <= 25000000))');
    }

    public function down(): void
    {
        Schema::dropIfExists('library.plex_item_artworks');
    }
};
