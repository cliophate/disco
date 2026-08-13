<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.recommendation_impressions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('recommendation_item_id')->index();
            $table->uuid('entity_id')->index();
            $table->string('surface', 32)->index();
            $table->string('context_key', 64);
            $table->jsonb('context')->default('{}');
            $table->timestampTz('presented_at')->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'recommendation_item_id', 'surface', 'context_key'], 'recommendation_impressions_context_unique');
        });
        DB::statement('ALTER TABLE discovery.recommendation_impressions ADD CONSTRAINT recommendation_impressions_user_fk FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE discovery.recommendation_impressions ADD CONSTRAINT recommendation_impressions_item_fk FOREIGN KEY (recommendation_item_id) REFERENCES discovery.recommendation_items(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE discovery.recommendation_impressions ADD CONSTRAINT recommendation_impressions_entity_fk FOREIGN KEY (entity_id) REFERENCES catalog.entities(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.recommendation_impressions');
    }
};
