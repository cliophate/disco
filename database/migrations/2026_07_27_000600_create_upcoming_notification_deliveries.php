<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.upcoming_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->string('channel', 24);
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('external_id')->nullable();
            $table->string('error_code')->nullable();
            $table->string('skip_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['notification_id', 'channel']);
            $table->index(['status', 'next_attempt_at']);
        });

        DB::statement('ALTER TABLE discovery.upcoming_notification_deliveries ADD CONSTRAINT upcoming_delivery_notification_fk FOREIGN KEY (notification_id) REFERENCES discovery.upcoming_release_notifications(id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE discovery.upcoming_notification_deliveries ADD CONSTRAINT upcoming_delivery_channel_check CHECK (channel IN ('gotify'))");
        DB::statement("ALTER TABLE discovery.upcoming_notification_deliveries ADD CONSTRAINT upcoming_delivery_status_check CHECK (status IN ('pending', 'sending', 'delivered', 'failed', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.upcoming_notification_deliveries');
    }
};
