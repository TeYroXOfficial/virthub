<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iso_images', function (Blueprint $table) {
            // Własny obraz klienta: tylko dla tej maszyny, tylko na jej węźle.
            $table->foreignId('server_id')->nullable()->after('is_public')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('iso_images', fn (Blueprint $table) => $table->dropConstrainedForeignId('server_id'));
    }
};
