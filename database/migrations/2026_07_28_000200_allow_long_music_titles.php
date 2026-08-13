<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library.plex_items', function (Blueprint $table): void {
            $table->text('title')->change();
            $table->text('sort_title')->nullable()->change();
        });

        Schema::table('catalog.entities', function (Blueprint $table): void {
            $table->text('canonical_name')->change();
            $table->text('sort_name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('library.plex_items', function (Blueprint $table): void {
            $table->string('title')->change();
            $table->string('sort_title')->nullable()->change();
        });

        Schema::table('catalog.entities', function (Blueprint $table): void {
            $table->string('canonical_name')->change();
            $table->string('sort_name')->change();
        });
    }
};
