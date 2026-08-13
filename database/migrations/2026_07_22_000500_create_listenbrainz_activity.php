<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS activity');

        Schema::create('source.accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');
            $table->uuid('owner_user_id');
            $table->string('external_username');
            $table->string('credential_env_key', 120);
            $table->jsonb('cursor')->default('{}');
            $table->string('status', 24)->default('active');
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['provider_id', 'owner_user_id', 'external_username']);
        });

        Schema::create('activity.listen_import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_account_id');
            $table->string('mode', 16);
            $table->string('status', 24);
            $table->jsonb('start_cursor')->default('{}');
            $table->jsonb('end_cursor')->default('{}');
            $table->jsonb('counts')->default('{}');
            $table->jsonb('errors')->default('[]');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['source_account_id', 'started_at']);
            $table->index(['status', 'completed_at']);
        });

        Schema::create('activity.listening_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_account_id');
            $table->uuid('source_snapshot_id');
            $table->char('fingerprint', 64);
            $table->timestampTz('listened_at');
            $table->unsignedBigInteger('listened_at_epoch');
            $table->text('supplied_artist');
            $table->text('supplied_release')->nullable();
            $table->text('supplied_track');
            $table->uuid('recording_msid')->nullable();
            $table->uuid('recording_mbid')->nullable();
            $table->uuid('release_mbid')->nullable();
            $table->uuid('release_group_mbid')->nullable();
            $table->jsonb('identifier_conflicts')->default('{}');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('music_service_name')->nullable();
            $table->string('media_player')->nullable();
            $table->string('submission_client')->nullable();
            $table->jsonb('raw_additional_info')->default('{}');
            $table->timestampTz('created_at');
            $table->unique(['source_account_id', 'fingerprint']);
            $table->index(['source_account_id', 'listened_at']);
        });

        Schema::create('activity.listening_event_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('listening_event_id')->unique();
            $table->uuid('recording_entity_id')->nullable();
            $table->uuid('release_group_entity_id')->nullable();
            $table->uuid('plex_track_item_id')->nullable();
            $table->string('status', 24);
            $table->string('method', 40);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->jsonb('evidence')->default('{}');
            $table->boolean('source_present')->default(true)->index();
            $table->uuid('last_seen_import_run_id');
            $table->timestampsTz();
            $table->index(['release_group_entity_id', 'status', 'source_present'], 'listening_matches_release_status_idx');
            $table->index(['source_present', 'status'], 'listening_matches_current_status_idx');
        });

        Schema::create('activity.play_aggregates', function (Blueprint $table) {
            $table->uuid('release_group_entity_id')->primary();
            $table->unsignedBigInteger('play_count');
            $table->timestampTz('first_listened_at');
            $table->timestampTz('last_listened_at');
            $table->timestampsTz();
        });

        $constraints = [
            'ALTER TABLE source.accounts ADD CONSTRAINT source_accounts_provider_fk FOREIGN KEY (provider_id) REFERENCES source.providers(id) ON DELETE RESTRICT',
            'ALTER TABLE source.accounts ADD CONSTRAINT source_accounts_owner_fk FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listen_import_runs ADD CONSTRAINT listen_import_runs_account_fk FOREIGN KEY (source_account_id) REFERENCES source.accounts(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_events ADD CONSTRAINT listening_events_account_fk FOREIGN KEY (source_account_id) REFERENCES source.accounts(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_events ADD CONSTRAINT listening_events_snapshot_fk FOREIGN KEY (source_snapshot_id) REFERENCES source.snapshots(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_event_matches ADD CONSTRAINT listening_matches_event_fk FOREIGN KEY (listening_event_id) REFERENCES activity.listening_events(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_event_matches ADD CONSTRAINT listening_matches_recording_fk FOREIGN KEY (recording_entity_id) REFERENCES catalog.recordings(entity_id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_event_matches ADD CONSTRAINT listening_matches_release_group_fk FOREIGN KEY (release_group_entity_id) REFERENCES catalog.release_groups(entity_id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_event_matches ADD CONSTRAINT listening_matches_plex_track_fk FOREIGN KEY (plex_track_item_id) REFERENCES library.plex_items(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.listening_event_matches ADD CONSTRAINT listening_matches_run_fk FOREIGN KEY (last_seen_import_run_id) REFERENCES activity.listen_import_runs(id) ON DELETE RESTRICT',
            'ALTER TABLE activity.play_aggregates ADD CONSTRAINT play_aggregates_release_group_fk FOREIGN KEY (release_group_entity_id) REFERENCES catalog.release_groups(entity_id) ON DELETE RESTRICT',
        ];

        foreach ($constraints as $constraint) {
            DB::statement($constraint);
        }

        DB::statement('ALTER TABLE activity.listening_event_matches ADD CONSTRAINT listening_match_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement("CREATE INDEX entity_metadata_release_group_mbid_idx ON catalog.entity_metadata ((attributes->>'release_group_mbid')) WHERE jsonb_exists(attributes, 'release_group_mbid')");
        DB::statement(<<<'SQL'
            CREATE FUNCTION activity.reject_listening_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'activity.listening_events is immutable';
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER listening_events_immutable
            BEFORE UPDATE OR DELETE ON activity.listening_events
            FOR EACH ROW EXECUTE FUNCTION activity.reject_listening_event_mutation()
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity.play_aggregates');
        Schema::dropIfExists('activity.listening_event_matches');
        Schema::dropIfExists('activity.listening_events');
        Schema::dropIfExists('activity.listen_import_runs');
        Schema::dropIfExists('source.accounts');
        DB::statement('DROP INDEX IF EXISTS catalog.entity_metadata_release_group_mbid_idx');
        DB::statement('DROP FUNCTION IF EXISTS activity.reject_listening_event_mutation()');
        DB::statement('DROP SCHEMA IF EXISTS activity');
    }
};
