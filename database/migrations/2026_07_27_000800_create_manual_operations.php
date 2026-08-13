<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app.manual_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_user_id');
            $table->string('operation_key', 80);
            $table->jsonb('parameters')->default('{}');
            $table->jsonb('result')->nullable();
            $table->string('concurrency_key', 160);
            $table->string('status', 16)->default('queued');
            $table->string('error_code', 120)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_owner_fk FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_key_check CHECK (operation_key IN ('plex.sync', 'listenbrainz.import', 'listenbrainz.recommendations', 'musicbrainz.enrich', 'discogs.enrich', 'artwork.discographies', 'upcoming.refresh', 'notifications.deliver'))");
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_status_check CHECK (status IN ('queued', 'running', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_parameters_check CHECK (jsonb_typeof(parameters) = 'object')");
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_result_check CHECK (result IS NULL OR jsonb_typeof(result) = 'object')");
        DB::statement("ALTER TABLE app.manual_operations ADD CONSTRAINT manual_operations_error_code_check CHECK (error_code IS NULL OR error_code ~ '^[a-z][a-z0-9_]{0,119}$')");
        DB::statement("CREATE UNIQUE INDEX manual_operations_active_concurrency_unique ON app.manual_operations (concurrency_key) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('app.manual_operations');
    }
};
