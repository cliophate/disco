<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.upcoming_release_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('release_group_id');
            $table->uuid('source_snapshot_id');
            $table->uuid('release_group_mbid');
            $table->uuid('release_mbid');
            $table->string('title');
            $table->string('artist_credit_name');
            $table->jsonb('artist_mbids');
            $table->date('release_date');
            $table->string('primary_type', 32);
            $table->string('personalization_type', 32);
            $table->text('personalization_reason');
            $table->string('source_provider', 64);
            $table->string('source_provider_name');
            $table->string('source_url');
            $table->string('status', 24)->default('active');
            $table->string('resolution_reason', 32)->nullable();
            $table->unsignedSmallInteger('absence_count')->default(0);
            $table->uuid('last_seen_generation_id');
            $table->uuid('last_evaluated_generation_id');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'release_group_id']);
            $table->index(['user_id', 'status', 'read_at']);
            $table->index(['user_id', 'release_date']);
        });

        DB::statement('ALTER TABLE discovery.upcoming_release_notifications ADD CONSTRAINT upcoming_notifications_user_fk FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE discovery.upcoming_release_notifications ADD CONSTRAINT upcoming_notifications_group_fk FOREIGN KEY (release_group_id) REFERENCES catalog.release_groups(entity_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE discovery.upcoming_release_notifications ADD CONSTRAINT upcoming_notifications_snapshot_fk FOREIGN KEY (source_snapshot_id) REFERENCES source.snapshots(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE discovery.upcoming_release_notifications ADD CONSTRAINT upcoming_notifications_status_check CHECK (status IN ('active', 'withdrawn', 'resolved'))");
        DB::statement("ALTER TABLE discovery.upcoming_release_notifications ADD CONSTRAINT upcoming_notifications_reason_check CHECK (resolution_reason IS NULL OR resolution_reason IN ('source_absent', 'outside_horizon', 'owned', 'released', 'no_longer_personalized'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.upcoming_release_notifications');
    }
};
