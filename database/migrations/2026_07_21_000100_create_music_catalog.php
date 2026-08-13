<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['app', 'catalog', 'source', 'library'] as $schema) {
            DB::statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
        }

        Schema::create('app.instances', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->uuid('owner_user_id')->unique();
            $table->string('name')->default('Disco');
            $table->timestampsTz();
        });

        Schema::create('catalog.entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 32)->index();
            $table->string('status', 24)->default('active')->index();
            $table->uuid('redirect_entity_id')->nullable();
            $table->string('canonical_name');
            $table->string('sort_name')->index();
            $table->string('disambiguation')->nullable();
            $table->timestampsTz();
            $table->index(['kind', 'sort_name']);
        });

        Schema::create('catalog.agents', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->string('agent_type', 32)->default('other');
            $table->smallInteger('begin_year')->nullable();
            $table->smallInteger('end_year')->nullable();
            $table->timestampsTz();
        });

        Schema::create('catalog.release_groups', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->string('primary_type', 32)->default('album');
            $table->jsonb('secondary_types')->default('[]');
            $table->smallInteger('first_release_year')->nullable()->index();
            $table->unsignedTinyInteger('first_release_month')->nullable();
            $table->unsignedTinyInteger('first_release_day')->nullable();
            $table->string('date_precision', 16)->default('unknown');
            $table->timestampsTz();
        });

        Schema::create('catalog.recordings', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();
        });

        Schema::create('catalog.releases', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->uuid('release_group_id')->index();
            $table->string('status', 32)->default('unknown');
            $table->string('country_code', 2)->nullable();
            $table->string('barcode')->nullable();
            $table->smallInteger('release_year')->nullable();
            $table->unsignedTinyInteger('release_month')->nullable();
            $table->unsignedTinyInteger('release_day')->nullable();
            $table->string('date_precision', 16)->default('unknown');
            $table->string('edition_summary')->nullable();
            $table->timestampsTz();
            $table->unique(['entity_id', 'release_group_id']);
        });

        Schema::create('catalog.media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('release_id');
            $table->unsignedSmallInteger('position');
            $table->string('title')->nullable();
            $table->string('format')->nullable();
            $table->timestampsTz();
            $table->unique(['release_id', 'position']);
        });

        Schema::create('catalog.medium_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('medium_id');
            $table->uuid('recording_id')->nullable()->index();
            $table->unsignedSmallInteger('position');
            $table->string('number_text')->nullable();
            $table->string('title');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();
            $table->unique(['medium_id', 'position']);
        });

        Schema::create('catalog.external_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('namespace', 80);
            $table->string('value', 255);
            $table->string('status', 24)->default('active');
            $table->timestampsTz();
            $table->unique(['namespace', 'value']);
            $table->index(['entity_id', 'namespace']);
        });

        Schema::create('source.providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(true);
            $table->jsonb('policy')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('source.objects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');
            $table->string('object_type', 64);
            $table->string('external_id', 255);
            $table->string('canonical_url')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
            $table->unique(['provider_id', 'object_type', 'external_id']);
        });

        Schema::create('source.snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_object_id');
            $table->timestampTz('retrieved_at');
            $table->unsignedSmallInteger('http_status');
            $table->string('payload_hash', 64);
            $table->jsonb('payload')->nullable();
            $table->string('parser_version', 32);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['source_object_id', 'retrieved_at']);
            $table->unique(['source_object_id', 'payload_hash']);
        });

        Schema::create('source.entity_resolutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_object_id');
            $table->uuid('entity_id');
            $table->string('status', 24)->default('candidate');
            $table->string('method', 40);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('algorithm_version', 32);
            $table->jsonb('evidence')->default('{}');
            $table->timestampsTz();
            $table->index(['source_object_id', 'status']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('source.assertions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('snapshot_id');
            $table->uuid('subject_entity_id');
            $table->string('predicate', 120);
            $table->jsonb('value');
            $table->string('status', 24)->default('observed');
            $table->decimal('confidence', 5, 4)->default(1);
            $table->timestampsTz();
            $table->unique(['snapshot_id', 'subject_entity_id', 'predicate']);
            $table->index(['subject_entity_id', 'predicate', 'status']);
        });

        Schema::create('library.plex_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('machine_identifier')->unique();
            $table->string('machine_identifier_hash', 64);
            $table->string('version')->nullable();
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
        });

        Schema::create('library.plex_libraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_server_id');
            $table->string('section_key', 32);
            $table->string('section_uuid')->nullable();
            $table->string('title');
            $table->string('library_type', 32);
            $table->string('agent')->nullable();
            $table->string('scanner')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['plex_server_id', 'section_key']);
            $table->unique(['plex_server_id', 'section_uuid']);
        });

        Schema::create('library.plex_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_library_id');
            $table->string('rating_key', 64);
            $table->string('item_type', 24)->index();
            $table->string('parent_rating_key', 64)->nullable()->index();
            $table->string('grandparent_rating_key', 64)->nullable()->index();
            $table->string('guid')->nullable();
            $table->string('title');
            $table->string('sort_title')->nullable();
            $table->smallInteger('year')->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('index_number')->nullable();
            $table->unsignedSmallInteger('disc_number')->nullable();
            $table->timestampTz('added_at_plex')->nullable();
            $table->timestampTz('updated_at_plex')->nullable();
            $table->timestampTz('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->nullable();
            $table->string('thumb_key')->nullable();
            $table->jsonb('raw_metadata')->default('{}');
            $table->timestampTz('last_synced_at');
            $table->timestampTz('removed_at')->nullable()->index();
            $table->timestampsTz();
            $table->unique(['plex_library_id', 'rating_key']);
            $table->index(['plex_library_id', 'item_type', 'removed_at']);
        });

        Schema::create('library.plex_item_guids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_item_id');
            $table->string('namespace', 64);
            $table->string('value', 255);
            $table->timestampsTz();
            $table->unique(['plex_item_id', 'namespace', 'value']);
        });

        Schema::create('library.plex_entity_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_item_id');
            $table->uuid('entity_id');
            $table->string('match_scope', 32);
            $table->string('status', 24)->default('confirmed');
            $table->string('method', 40);
            $table->decimal('confidence', 5, 4)->default(1);
            $table->timestampsTz();
            $table->unique(['plex_item_id', 'entity_id', 'match_scope']);
            $table->index(['entity_id', 'status']);
        });

        Schema::create('library.holdings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('release_group_id')->index();
            $table->uuid('release_id')->nullable()->index();
            $table->uuid('plex_album_item_id')->unique();
            $table->string('ownership_type', 24)->default('digital');
            $table->boolean('is_primary_playback_copy')->default(true);
            $table->timestampsTz();
        });

        Schema::create('library.plex_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plex_library_id');
            $table->string('status', 24);
            $table->jsonb('counts')->default('{}');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['plex_library_id', 'started_at']);
        });

        $constraints = [
            'ALTER TABLE app.instances ADD CONSTRAINT instances_owner_fk FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE RESTRICT',
            'ALTER TABLE catalog.entities ADD CONSTRAINT entities_redirect_fk FOREIGN KEY (redirect_entity_id) REFERENCES catalog.entities(id) ON DELETE SET NULL',
            'ALTER TABLE catalog.agents ADD CONSTRAINT agents_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE catalog.release_groups ADD CONSTRAINT release_groups_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE catalog.recordings ADD CONSTRAINT recordings_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE catalog.releases ADD CONSTRAINT releases_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE catalog.releases ADD CONSTRAINT releases_group_fk FOREIGN KEY (release_group_id) REFERENCES catalog.release_groups(entity_id) ON DELETE CASCADE',
            'ALTER TABLE catalog.media ADD CONSTRAINT media_release_fk FOREIGN KEY (release_id) REFERENCES catalog.releases(entity_id) ON DELETE CASCADE',
            'ALTER TABLE catalog.medium_tracks ADD CONSTRAINT medium_tracks_medium_fk FOREIGN KEY (medium_id) REFERENCES catalog.media(id) ON DELETE CASCADE',
            'ALTER TABLE catalog.medium_tracks ADD CONSTRAINT medium_tracks_recording_fk FOREIGN KEY (recording_id) REFERENCES catalog.recordings(entity_id) ON DELETE SET NULL',
            'ALTER TABLE catalog.external_identifiers ADD CONSTRAINT external_identifiers_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE source.objects ADD CONSTRAINT source_objects_provider_fk FOREIGN KEY (provider_id) REFERENCES source.providers(id) ON DELETE CASCADE',
            'ALTER TABLE source.snapshots ADD CONSTRAINT source_snapshots_object_fk FOREIGN KEY (source_object_id) REFERENCES source.objects(id) ON DELETE CASCADE',
            'ALTER TABLE source.entity_resolutions ADD CONSTRAINT entity_resolutions_object_fk FOREIGN KEY (source_object_id) REFERENCES source.objects(id) ON DELETE CASCADE',
            'ALTER TABLE source.entity_resolutions ADD CONSTRAINT entity_resolutions_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE source.assertions ADD CONSTRAINT assertions_snapshot_fk FOREIGN KEY (snapshot_id) REFERENCES source.snapshots(id) ON DELETE CASCADE',
            'ALTER TABLE source.assertions ADD CONSTRAINT assertions_subject_fk FOREIGN KEY (subject_entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE library.plex_libraries ADD CONSTRAINT plex_libraries_server_fk FOREIGN KEY (plex_server_id) REFERENCES library.plex_servers(id) ON DELETE CASCADE',
            'ALTER TABLE library.plex_items ADD CONSTRAINT plex_items_library_fk FOREIGN KEY (plex_library_id) REFERENCES library.plex_libraries(id) ON DELETE CASCADE',
            'ALTER TABLE library.plex_item_guids ADD CONSTRAINT plex_item_guids_item_fk FOREIGN KEY (plex_item_id) REFERENCES library.plex_items(id) ON DELETE CASCADE',
            'ALTER TABLE library.plex_entity_matches ADD CONSTRAINT plex_entity_matches_item_fk FOREIGN KEY (plex_item_id) REFERENCES library.plex_items(id) ON DELETE CASCADE',
            'ALTER TABLE library.plex_entity_matches ADD CONSTRAINT plex_entity_matches_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE library.holdings ADD CONSTRAINT holdings_release_group_fk FOREIGN KEY (release_group_id) REFERENCES catalog.release_groups(entity_id) ON DELETE CASCADE',
            'ALTER TABLE library.holdings ADD CONSTRAINT holdings_release_hierarchy_fk FOREIGN KEY (release_id, release_group_id) REFERENCES catalog.releases(entity_id, release_group_id) ON DELETE RESTRICT',
            'ALTER TABLE library.holdings ADD CONSTRAINT holdings_plex_album_fk FOREIGN KEY (plex_album_item_id) REFERENCES library.plex_items(id) ON DELETE CASCADE',
            'ALTER TABLE library.plex_sync_runs ADD CONSTRAINT plex_sync_runs_library_fk FOREIGN KEY (plex_library_id) REFERENCES library.plex_libraries(id) ON DELETE CASCADE',
        ];

        foreach ($constraints as $constraint) {
            DB::statement($constraint);
        }

        DB::statement("CREATE UNIQUE INDEX plex_one_active_match_per_scope ON library.plex_entity_matches (plex_item_id, match_scope) WHERE status IN ('confirmed', 'candidate')");
        DB::statement("CREATE UNIQUE INDEX source_one_active_resolution ON source.entity_resolutions (source_object_id) WHERE status IN ('confirmed', 'candidate')");
        DB::statement('CREATE UNIQUE INDEX holdings_one_primary_per_release_group ON library.holdings (release_group_id) WHERE is_primary_playback_copy');
        DB::statement('ALTER TABLE library.plex_entity_matches ADD CONSTRAINT plex_match_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement('ALTER TABLE source.entity_resolutions ADD CONSTRAINT resolution_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
        DB::statement('ALTER TABLE source.assertions ADD CONSTRAINT assertion_confidence_range CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        foreach (['library', 'source', 'catalog', 'app'] as $schema) {
            DB::statement("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
    }
};
