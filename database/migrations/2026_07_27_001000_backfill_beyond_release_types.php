<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE discovery.recommendation_items AS items
            SET eligibility = COALESCE(items.eligibility, '{}'::jsonb)
                    || jsonb_build_object('release_type', lower(groups.primary_type)),
                updated_at = NOW()
            FROM discovery.recommendation_runs AS runs,
                 catalog.release_groups AS groups
            WHERE runs.id = items.run_id
              AND groups.entity_id = items.entity_id
              AND runs.intent = 'beyond_library'
              AND NOT jsonb_exists(COALESCE(items.eligibility, '{}'::jsonb), 'release_type')
              AND lower(groups.primary_type) IN ('album', 'ep', 'single')
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE discovery.recommendation_items AS items
            SET eligibility = COALESCE(items.eligibility, '{}'::jsonb) - 'release_type',
                updated_at = NOW()
            FROM discovery.recommendation_runs AS runs
            WHERE runs.id = items.run_id
              AND runs.intent = 'beyond_library'
            SQL);
    }
};
