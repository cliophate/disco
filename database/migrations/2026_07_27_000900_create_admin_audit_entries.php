<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app.admin_audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_user_id');
            $table->string('action', 80);
            $table->string('subject', 160)->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['owner_user_id', 'created_at']);
        });

        DB::statement('ALTER TABLE app.admin_audit_entries ADD CONSTRAINT admin_audit_entries_owner_fk FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE app.admin_audit_entries ADD CONSTRAINT admin_audit_entries_action_check CHECK (action IN ('credential_activated', 'credential_removed', 'operation_queued'))");
        DB::statement("ALTER TABLE app.admin_audit_entries ADD CONSTRAINT admin_audit_entries_context_check CHECK (jsonb_typeof(context) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('app.admin_audit_entries');
    }
};
