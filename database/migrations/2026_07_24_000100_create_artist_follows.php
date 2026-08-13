<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery.artist_follows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('artist_entity_id');
            $table->timestampsTz();
            $table->unique(['user_id', 'artist_entity_id']);
            $table->foreign('artist_entity_id')->references('entity_id')->on('catalog.agents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.artist_follows');
    }
};
