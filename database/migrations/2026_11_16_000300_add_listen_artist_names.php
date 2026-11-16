<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity.listen_identities', function (Blueprint $table): void {
            $table->jsonb('artist_names')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('activity.listen_identities', function (Blueprint $table): void {
            $table->dropColumn('artist_names');
        });
    }
};
