<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.entity_metadata', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->string('source_provider', 64);
            $table->jsonb('genres')->default('[]');
            $table->string('primary_type', 80)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('area')->nullable();
            $table->smallInteger('begin_year')->nullable();
            $table->unsignedTinyInteger('begin_month')->nullable();
            $table->unsignedTinyInteger('begin_day')->nullable();
            $table->string('begin_precision', 16)->nullable();
            $table->smallInteger('end_year')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->unsignedTinyInteger('end_day')->nullable();
            $table->string('end_precision', 16)->nullable();
            $table->smallInteger('first_release_year')->nullable();
            $table->unsignedTinyInteger('first_release_month')->nullable();
            $table->unsignedTinyInteger('first_release_day')->nullable();
            $table->string('first_release_precision', 16)->nullable();
            $table->text('disambiguation')->nullable();
            $table->jsonb('artist_credit')->default('[]');
            $table->jsonb('external_links')->default('[]');
            $table->jsonb('attributes')->default('{}');
            $table->timestampTz('enriched_at');
            $table->timestampsTz();
        });

        Schema::create('source.canonical_field_choices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('entity_id');
            $table->string('predicate', 120);
            $table->uuid('assertion_id');
            $table->string('selected_by', 40)->default('provider_policy');
            $table->timestampsTz();
            $table->unique(['entity_id', 'predicate']);
        });

        DB::statement('ALTER TABLE catalog.entity_metadata ADD CONSTRAINT entity_metadata_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE source.canonical_field_choices ADD CONSTRAINT canonical_field_choices_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE source.canonical_field_choices ADD CONSTRAINT canonical_field_choices_assertion_fk FOREIGN KEY (assertion_id) REFERENCES source.assertions(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE catalog.entity_metadata ADD CONSTRAINT entity_metadata_months_check CHECK ((begin_month IS NULL OR begin_month BETWEEN 1 AND 12) AND (end_month IS NULL OR end_month BETWEEN 1 AND 12) AND (first_release_month IS NULL OR first_release_month BETWEEN 1 AND 12))');
        DB::statement('ALTER TABLE catalog.entity_metadata ADD CONSTRAINT entity_metadata_days_check CHECK ((begin_day IS NULL OR begin_day BETWEEN 1 AND 31) AND (end_day IS NULL OR end_day BETWEEN 1 AND 31) AND (first_release_day IS NULL OR first_release_day BETWEEN 1 AND 31))');
    }

    public function down(): void
    {
        Schema::dropIfExists('source.canonical_field_choices');
        Schema::dropIfExists('catalog.entity_metadata');
    }
};
