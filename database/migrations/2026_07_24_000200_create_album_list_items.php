<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.album_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('release_group_entity_id');
            $table->string('status', 24);
            $table->text('note')->nullable();
            $table->string('source')->nullable();
            $table->timestampTz('wanted_at')->nullable();
            $table->timestampTz('listened_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampTz('state_changed_at');
            $table->timestampsTz();
            $table->unique(['user_id', 'release_group_entity_id']);
            $table->index(['user_id', 'status', 'state_changed_at', 'release_group_entity_id'], 'album_list_user_status_changed');
            $table->foreign('release_group_entity_id')->references('entity_id')->on('catalog.release_groups')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE discovery.album_list_items ADD CONSTRAINT album_list_status_check CHECK (status IN ('want_to_listen', 'listened', 'removed'))");
        DB::statement("ALTER TABLE discovery.album_list_items ADD CONSTRAINT album_list_timestamp_check CHECK ((status <> 'want_to_listen' OR wanted_at IS NOT NULL) AND (status <> 'listened' OR listened_at IS NOT NULL) AND (status <> 'removed' OR removed_at IS NOT NULL) AND (status = 'removed' OR removed_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.album_list_items');
    }
};
