<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS discovery');
        Schema::create('discovery.home_editions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version_hash', 64)->unique();
            $table->string('algorithm_version', 80);
            $table->timestampTz('facts_as_of');
            $table->jsonb('payload');
            $table->timestampTz('generated_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery.home_editions');
        DB::statement('DROP SCHEMA IF EXISTS discovery');
    }
};
