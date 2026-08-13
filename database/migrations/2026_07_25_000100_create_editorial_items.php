<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.editorial_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_snapshot_id');
            $table->string('source', 32);
            $table->string('feed_url');
            $table->string('guid');
            $table->string('canonical_url')->unique();
            $table->string('headline');
            $table->text('excerpt')->nullable();
            $table->string('author')->nullable();
            $table->string('publisher', 80);
            $table->string('category', 80)->nullable();
            $table->text('image_url')->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->timestampTz('published_at')->index();
            $table->timestampTz('retrieved_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
            $table->unique(['source', 'guid']);
        });

        DB::statement('ALTER TABLE discovery.editorial_items ADD CONSTRAINT editorial_items_snapshot_fk FOREIGN KEY (source_snapshot_id) REFERENCES source.snapshots(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE discovery.editorial_items ADD CONSTRAINT editorial_items_source_check CHECK (source = 'pitchfork')");
        DB::statement("ALTER TABLE discovery.editorial_items ADD CONSTRAINT editorial_items_https_check CHECK (feed_url LIKE 'https://%' AND canonical_url LIKE 'https://%')");
        DB::statement("ALTER TABLE discovery.editorial_items ADD CONSTRAINT editorial_items_image_check CHECK (image_url IS NULL OR image_url LIKE 'https://media.pitchfork.com/%')");
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.editorial_items');
    }
};
