<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.entity_artworks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->uuid('source_release_mbid')->nullable();
            $table->string('source_image_id', 40)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('storage_key')->nullable();
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

        DB::statement('ALTER TABLE catalog.entity_artworks ADD CONSTRAINT entity_artworks_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE catalog.entity_artworks ADD CONSTRAINT entity_artworks_status_check CHECK (status IN ('pending', 'ready', 'stale', 'failed', 'missing'))");
        DB::statement('ALTER TABLE catalog.entity_artworks ADD CONSTRAINT entity_artworks_dimensions_check CHECK ((width IS NULL AND height IS NULL) OR (width > 0 AND height > 0 AND width::bigint * height::bigint <= 25000000))');
        DB::statement("ALTER TABLE catalog.entity_artworks ADD CONSTRAINT entity_artworks_ready_fields_check CHECK (status NOT IN ('ready', 'stale') OR (content_sha256 IS NOT NULL AND storage_key IS NOT NULL AND mime_type = 'image/webp' AND size_bytes > 0 AND width > 0 AND height > 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.entity_artworks');
    }
};
