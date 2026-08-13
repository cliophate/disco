<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.upcoming_release_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_snapshot_id');
            $table->string('algorithm_version', 40);
            $table->unsignedSmallInteger('horizon_days');
            $table->text('horizon_reason');
            $table->jsonb('coverage');
            $table->timestampTz('generated_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });

        Schema::create('discovery.upcoming_release_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('generation_id');
            $table->uuid('release_group_id');
            $table->uuid('release_group_mbid');
            $table->uuid('release_mbid');
            $table->string('title');
            $table->string('artist_credit_name');
            $table->jsonb('artist_mbids');
            $table->date('release_date');
            $table->string('primary_type', 32);
            $table->jsonb('secondary_types');
            $table->string('artwork_status', 24);
            $table->uuid('caa_release_mbid')->nullable();
            $table->string('caa_id', 32)->nullable();
            $table->unsignedBigInteger('listen_count')->default(0);
            $table->jsonb('tags');
            $table->unsignedInteger('general_rank');
            $table->jsonb('provenance');
            $table->timestampsTz();
            $table->unique(['generation_id', 'release_group_id']);
            $table->index(['generation_id', 'general_rank']);
            $table->index(['generation_id', 'release_date']);
        });

        DB::statement('ALTER TABLE discovery.upcoming_release_generations ADD CONSTRAINT upcoming_generations_snapshot_fk FOREIGN KEY (source_snapshot_id) REFERENCES source.snapshots(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE discovery.upcoming_release_generations ADD CONSTRAINT upcoming_generations_horizon_check CHECK (horizon_days IN (30, 60))');
        DB::statement('ALTER TABLE discovery.upcoming_release_items ADD CONSTRAINT upcoming_items_generation_fk FOREIGN KEY (generation_id) REFERENCES discovery.upcoming_release_generations(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE discovery.upcoming_release_items ADD CONSTRAINT upcoming_items_group_fk FOREIGN KEY (release_group_id) REFERENCES catalog.release_groups(entity_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE discovery.upcoming_release_items ADD CONSTRAINT upcoming_items_type_check CHECK (lower(primary_type) IN ('album', 'ep'))");
        DB::statement("ALTER TABLE discovery.upcoming_release_items ADD CONSTRAINT upcoming_items_artwork_check CHECK (artwork_status IN ('available', 'unavailable'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.upcoming_release_items');
        Schema::dropIfExists('discovery.upcoming_release_generations');
    }
};
