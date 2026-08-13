<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app.provider_credentials', function (Blueprint $table): void {
            $table->string('provider')->primary();
            $table->text('credentials');
            $table->timestampTz('tested_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE app.provider_credentials ADD CONSTRAINT provider_credentials_provider_check CHECK (provider IN ('plex', 'listenbrainz', 'discogs', 'gotify', 'theaudiodb'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('app.provider_credentials');
    }
};
