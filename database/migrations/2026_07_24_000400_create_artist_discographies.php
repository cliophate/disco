<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.artist_discography_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('artist_entity_id')->index();
            $table->uuid('artist_mbid');
            $table->unsignedInteger('source_total');
            $table->unsignedSmallInteger('page_count');
            $table->boolean('truncated')->default(false);
            $table->string('algorithm_version', 40);
            $table->timestampTz('generated_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });

        Schema::create('discovery.artist_discography_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id');
            $table->uuid('release_group_id');
            $table->uuid('release_group_mbid');
            $table->string('title');
            $table->jsonb('artist_credit');
            $table->string('primary_type', 32)->nullable();
            $table->jsonb('secondary_types');
            $table->smallInteger('first_release_year')->nullable();
            $table->unsignedTinyInteger('first_release_month')->nullable();
            $table->unsignedTinyInteger('first_release_day')->nullable();
            $table->string('date_precision', 16)->default('unknown');
            $table->uuid('official_release_mbid');
            $table->date('official_release_date')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestampsTz();
            $table->unique(['generation_id', 'release_group_mbid'], 'artist_discography_generation_mbid_unique');
            $table->unique(['generation_id', 'release_group_id'], 'artist_discography_generation_entity_unique');
            $table->index(['generation_id', 'position']);
        });

        DB::statement('ALTER TABLE discovery.artist_discography_generations ADD CONSTRAINT artist_discography_generation_artist_fk FOREIGN KEY (artist_entity_id) REFERENCES catalog.agents(entity_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE discovery.artist_discography_items ADD CONSTRAINT artist_discography_items_generation_fk FOREIGN KEY (generation_id) REFERENCES discovery.artist_discography_generations(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE discovery.artist_discography_items ADD CONSTRAINT artist_discography_items_group_fk FOREIGN KEY (release_group_id) REFERENCES catalog.release_groups(entity_id) ON DELETE RESTRICT');
        Schema::table('catalog.entities', fn (Blueprint $table) => $table->index('redirect_entity_id', 'catalog_entities_redirect_index'));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalog.catalog_entities_redirect_index');
        Schema::dropIfExists('discovery.artist_discography_items');
        Schema::dropIfExists('discovery.artist_discography_generations');
    }
};
