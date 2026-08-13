<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog.entity_narratives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('entity_id')->index();
            $table->string('provider_slug', 64);
            $table->string('kind', 24)->default('description');
            $table->string('language', 12)->default('en');
            $table->string('status', 24)->default('missing')->index();
            $table->text('body')->nullable();
            $table->string('source_url')->nullable();
            $table->string('external_id')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('license_name', 80)->nullable();
            $table->string('license_url')->nullable();
            $table->timestampTz('fetched_at');
            $table->timestampsTz();
            $table->unique(['entity_id', 'provider_slug', 'kind', 'language'], 'entity_narratives_provider_unique');
        });

        DB::statement('ALTER TABLE catalog.entity_narratives ADD CONSTRAINT entity_narratives_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE catalog.entity_narratives ADD CONSTRAINT entity_narratives_status_check CHECK (status IN ('ready', 'missing', 'failed', 'stale'))");
        DB::statement("ALTER TABLE catalog.entity_narratives ADD CONSTRAINT entity_narratives_ready_fields_check CHECK (status <> 'ready' OR (kind = 'description' AND body IS NOT NULL AND source_url IS NOT NULL AND content_sha256 IS NOT NULL AND license_name IS NOT NULL AND license_url IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.entity_narratives');
    }
};
