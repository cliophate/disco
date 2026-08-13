<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library.plex_media_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_item_id');
            $table->string('media_id', 64);
            $table->string('part_id', 64);
            $table->string('part_key', 512);
            $table->string('media_version', 64);
            $table->string('container', 32)->nullable();
            $table->string('audio_codec', 32)->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->unsignedInteger('sample_rate_hz')->nullable();
            $table->unsignedInteger('bitrate_kbps')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('last_synced_at');
            $table->timestampsTz();
            $table->unique(['plex_item_id', 'media_id', 'part_id']);
            $table->index(['plex_item_id', 'audio_codec']);
        });

        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_item_fk FOREIGN KEY (plex_item_id) REFERENCES library.plex_items(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_size_positive CHECK (size_bytes > 0)');
        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_size_bounded CHECK (size_bytes <= 68719476736)');
        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_duration_bounded CHECK (duration_ms IS NULL OR duration_ms BETWEEN 1 AND 86400000)');
        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_channels_range CHECK (channels IS NULL OR channels BETWEEN 1 AND 32)');
        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_bit_depth_range CHECK (bit_depth IS NULL OR bit_depth BETWEEN 1 AND 64)');
        DB::statement('ALTER TABLE library.plex_media_parts ADD CONSTRAINT plex_media_parts_sample_rate_range CHECK (sample_rate_hz IS NULL OR sample_rate_hz BETWEEN 1000 AND 768000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('library.plex_media_parts');
    }
};
