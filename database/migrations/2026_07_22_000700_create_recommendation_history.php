<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.recommendation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('intent', 48)->index();
            $table->jsonb('input')->default('{}');
            $table->string('algorithm_version', 64);
            $table->string('configuration_hash', 64);
            $table->unsignedBigInteger('random_seed');
            $table->string('catalog_version', 64)->index();
            $table->string('status', 24)->index();
            $table->timestampTz('generated_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'intent', 'catalog_version']);
        });

        Schema::create('discovery.recommendation_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->uuid('entity_id')->index();
            $table->unsignedSmallInteger('rank');
            $table->decimal('score', 10, 6);
            $table->jsonb('component_scores')->default('{}');
            $table->jsonb('eligibility')->default('{}');
            $table->string('module_type', 64);
            $table->text('explanation_text');
            $table->string('explanation_version', 32);
            $table->timestampsTz();
            $table->unique(['run_id', 'entity_id']);
            $table->unique(['run_id', 'rank']);
        });

        Schema::create('discovery.recommendation_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('recommendation_item_id');
            $table->string('evidence_type', 64);
            $table->uuid('subject_entity_id');
            $table->string('predicate', 120)->nullable();
            $table->uuid('object_entity_id')->nullable();
            $table->uuid('source_provider_id')->nullable();
            $table->string('source_slug', 64);
            $table->decimal('weight', 10, 6)->default(1);
            $table->text('display_text');
            $table->timestampsTz();
            $table->index(['recommendation_item_id', 'evidence_type']);
        });

        Schema::create('discovery.recommendation_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('entity_id')->index();
            $table->uuid('recommendation_item_id')->nullable()->index();
            $table->string('action', 32)->index();
            $table->string('reason')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'entity_id']);
        });

        Schema::create('discovery.feedback_revisions', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestampsTz();
        });

        DB::table('discovery.home_editions')->delete();
        Schema::table('discovery.home_editions', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('recommendation_run_id')->nullable()->unique();
        });
        DB::statement('ALTER TABLE discovery.home_editions ALTER COLUMN user_id SET NOT NULL');
        DB::statement('ALTER TABLE discovery.home_editions ALTER COLUMN recommendation_run_id SET NOT NULL');
        Schema::table('discovery.home_editions', function (Blueprint $table): void {
            $table->dropUnique(['version_hash']);
            $table->unique(['user_id', 'version_hash']);
        });

        $constraints = [
            'ALTER TABLE discovery.recommendation_runs ADD CONSTRAINT recommendation_runs_user_fk FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.recommendation_items ADD CONSTRAINT recommendation_items_run_fk FOREIGN KEY (run_id) REFERENCES discovery.recommendation_runs(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.recommendation_items ADD CONSTRAINT recommendation_items_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE RESTRICT',
            'ALTER TABLE discovery.recommendation_evidence ADD CONSTRAINT recommendation_evidence_item_fk FOREIGN KEY (recommendation_item_id) REFERENCES discovery.recommendation_items(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.recommendation_evidence ADD CONSTRAINT recommendation_evidence_subject_fk FOREIGN KEY (subject_entity_id) REFERENCES catalog.entities(id) ON DELETE RESTRICT',
            'ALTER TABLE discovery.recommendation_evidence ADD CONSTRAINT recommendation_evidence_object_fk FOREIGN KEY (object_entity_id) REFERENCES catalog.entities(id) ON DELETE SET NULL',
            'ALTER TABLE discovery.recommendation_evidence ADD CONSTRAINT recommendation_evidence_provider_fk FOREIGN KEY (source_provider_id) REFERENCES source.providers(id) ON DELETE SET NULL',
            'ALTER TABLE discovery.recommendation_feedback ADD CONSTRAINT recommendation_feedback_user_fk FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.recommendation_feedback ADD CONSTRAINT recommendation_feedback_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.recommendation_feedback ADD CONSTRAINT recommendation_feedback_item_fk FOREIGN KEY (recommendation_item_id) REFERENCES discovery.recommendation_items(id) ON DELETE SET NULL',
            'ALTER TABLE discovery.feedback_revisions ADD CONSTRAINT feedback_revisions_user_fk FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.home_editions ADD CONSTRAINT home_editions_user_fk FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE',
            'ALTER TABLE discovery.home_editions ADD CONSTRAINT home_editions_recommendation_run_fk FOREIGN KEY (recommendation_run_id) REFERENCES discovery.recommendation_runs(id) ON DELETE RESTRICT',
        ];
        foreach ($constraints as $constraint) {
            DB::statement($constraint);
        }

        DB::statement("ALTER TABLE discovery.recommendation_runs ADD CONSTRAINT recommendation_runs_status_check CHECK (status IN ('pending', 'running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE discovery.recommendation_feedback ADD CONSTRAINT recommendation_feedback_action_check CHECK (action IN ('interested', 'not_for_me', 'already_know', 'wrong_match'))");
        DB::statement('ALTER TABLE discovery.recommendation_items ADD CONSTRAINT recommendation_items_score_check CHECK (score >= 0 AND score <= 1)');
        DB::statement('ALTER TABLE discovery.recommendation_evidence ADD CONSTRAINT recommendation_evidence_weight_check CHECK (weight >= 0 AND weight <= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE discovery.home_editions DROP CONSTRAINT IF EXISTS home_editions_recommendation_run_fk');
        DB::statement('ALTER TABLE discovery.home_editions DROP CONSTRAINT IF EXISTS home_editions_user_fk');
        Schema::table('discovery.home_editions', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'version_hash']);
            $table->dropUnique(['recommendation_run_id']);
            $table->dropColumn(['user_id', 'recommendation_run_id']);
            $table->unique('version_hash');
        });
        Schema::dropIfExists('discovery.recommendation_feedback');
        Schema::dropIfExists('discovery.feedback_revisions');
        Schema::dropIfExists('discovery.recommendation_evidence');
        Schema::dropIfExists('discovery.recommendation_items');
        Schema::dropIfExists('discovery.recommendation_runs');
    }
};
