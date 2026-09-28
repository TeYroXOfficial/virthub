<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ochrona przed nadużyciami w aplikacjach (PteroVM, koparki, zdalne powłoki):
 * ostatnie zgłoszenie z węzła i zwolnienie z ochrony nadawane przez personel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_servers', function (Blueprint $table) {
            $table->timestamp('abuse_detected_at')->nullable()->after('suspension_reason');
            $table->json('abuse_findings')->nullable()->after('abuse_detected_at');
            $table->boolean('abuse_exempt')->default(false)->after('abuse_findings');
        });
    }

    public function down(): void
    {
        Schema::table('app_servers', function (Blueprint $table) {
            $table->dropColumn(['abuse_detected_at', 'abuse_findings', 'abuse_exempt']);
        });
    }
};
