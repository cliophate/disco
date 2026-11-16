<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity.listen_identities', function (Blueprint $table): void {
            $table->uuid('listening_event_id')->primary();
            $table->jsonb('artist_mbids')->default('[]');
            $table->uuid('release_group_mbid')->nullable()->index();
            $table->timestampsTz();
            $table->foreign('listening_event_id')->references('id')->on('activity.listening_events')->cascadeOnDelete();
        });
        // Existing immutable events can supply submitted IDs immediately. Full import adds mapped IDs.
        DB::statement("INSERT INTO activity.listen_identities (listening_event_id, artist_mbids, release_group_mbid, created_at, updated_at) SELECT id, CASE WHEN jsonb_typeof(raw_additional_info->'artist_mbids') = 'array' THEN raw_additional_info->'artist_mbids' ELSE '[]'::jsonb END, release_group_mbid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM activity.listening_events");
        Schema::create('discovery.batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('section', 24);
            $table->date('period');
            $table->string('status', 24)->default('running');
            $table->jsonb('summary')->default('{}');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['section', 'period']);
        });
        Schema::create('discovery.candidates', function (Blueprint $table): void {
            $table->uuid('artist_mbid')->primary();
            $table->text('artist_name');
            $table->double('score');
            $table->jsonb('seeds');
            $table->unsignedBigInteger('listener_count')->nullable();
            $table->uuid('batch_id');
            $table->timestampsTz();
            $table->foreign('batch_id')->references('id')->on('discovery.batches')->cascadeOnDelete();
        });
        Schema::create('discovery.picks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->uuid('entity_id')->nullable();
            $table->uuid('artist_mbid');
            $table->text('artist_name');
            $table->uuid('release_group_mbid')->index();
            $table->text('title');
            $table->string('release_type', 8);
            $table->string('release_date', 10)->nullable();
            $table->text('reason');
            $table->jsonb('seeds')->default('[]');
            $table->text('qobuz_url');
            $table->string('qobuz_status', 8);
            $table->string('state', 16)->default('new');
            $table->unsignedSmallInteger('position');
            $table->timestampTz('in_plex_at')->nullable();
            $table->timestampTz('played_at')->nullable();
            $table->timestampTz('passed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['batch_id', 'release_group_mbid']);
            $table->foreign('batch_id')->references('id')->on('discovery.batches')->cascadeOnDelete();
            $table->foreign('entity_id')->references('id')->on('catalog.entities')->nullOnDelete();
            $table->index(['artist_mbid', 'created_at']);
        });
        Schema::create('discovery.passes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 8);
            $table->uuid('mbid');
            $table->text('normalized_name');
            $table->timestampsTz();
            $table->unique(['scope', 'mbid']);
        });
        DB::statement("ALTER TABLE discovery.batches ADD CHECK (section IN ('new_artists', 'new_releases', 'more'))");
        DB::statement("ALTER TABLE discovery.batches ADD CHECK (status IN ('running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE discovery.picks ADD CHECK (state IN ('new', 'in_plex', 'played'))");
        DB::statement("ALTER TABLE discovery.picks ADD CHECK (release_type IN ('Album', 'EP'))");
        DB::statement("ALTER TABLE discovery.passes ADD CHECK (scope IN ('artist', 'album'))");
    }

    public function down(): void
    {
        foreach (['discovery.passes', 'discovery.picks', 'discovery.candidates', 'discovery.batches', 'activity.listen_identities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
