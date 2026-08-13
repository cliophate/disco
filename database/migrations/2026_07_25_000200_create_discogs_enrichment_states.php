<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source.discogs_enrichment_states', function (Blueprint $table) {
            $table->uuid('entity_id')->primary();
            $table->string('status', 32)->index();
            $table->timestampTz('attempted_at');
            $table->timestampTz('retry_at')->nullable()->index();
            $table->string('error_code', 120)->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE source.discogs_enrichment_states ADD CONSTRAINT discogs_enrichment_states_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('source.discogs_enrichment_states');
    }
};
