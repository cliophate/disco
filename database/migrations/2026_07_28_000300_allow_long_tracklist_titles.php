<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog.media', function (Blueprint $table): void {
            $table->text('title')->nullable()->change();
        });

        Schema::table('catalog.medium_tracks', function (Blueprint $table): void {
            $table->text('title')->change();
        });
    }

    public function down(): void
    {
        Schema::table('catalog.media', function (Blueprint $table): void {
            $table->string('title')->nullable()->change();
        });

        Schema::table('catalog.medium_tracks', function (Blueprint $table): void {
            $table->string('title')->change();
        });
    }
};
