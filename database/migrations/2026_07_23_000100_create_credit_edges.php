<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.credit_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subject_entity_id');
            $table->string('source_key', 64);
            $table->string('role', 32)->index();
            $table->string('credited_name')->nullable();
            $table->uuid('target_entity_id')->nullable()->index();
            $table->uuid('source_snapshot_id')->index();
            $table->unsignedSmallInteger('position');
            $table->jsonb('attributes')->default('{}');
            $table->timestampsTz();
            $table->unique(['subject_entity_id', 'source_key']);
            $table->index(['subject_entity_id', 'role', 'position']);
        });
        DB::statement('ALTER TABLE catalog.credit_edges ADD CONSTRAINT credit_edges_subject_fk FOREIGN KEY (subject_entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE catalog.credit_edges ADD CONSTRAINT credit_edges_target_fk FOREIGN KEY (target_entity_id) REFERENCES catalog.entities(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE catalog.credit_edges ADD CONSTRAINT credit_edges_snapshot_fk FOREIGN KEY (source_snapshot_id) REFERENCES source.snapshots(id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE catalog.credit_edges ADD CONSTRAINT credit_edges_role_check CHECK (role IN ('performer', 'producer', 'songwriter', 'engineer', 'work', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.credit_edges');
    }
};
