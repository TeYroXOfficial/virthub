<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Elevate: od kiedy zapisy dysku idą do nakładki (null = brak).
            $table->timestamp('elevated_at')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('elevated_at'));
    }
};
