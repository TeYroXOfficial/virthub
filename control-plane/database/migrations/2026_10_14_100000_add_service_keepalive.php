<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potwierdzanie aktywności: klient co pewien czas klika „Przedłuż”, inaczej
 * usługa jest zawieszana (typowo dla usług za darmo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('keepalive_interval', 10)->nullable(); // kod okresu, np. 1d — ważność po kliknięciu
            $table->string('keepalive_window', 10)->nullable();   // ile przed wygaśnięciem odblokowuje się przycisk
        });
        Schema::table('billing_services', function (Blueprint $table) {
            $table->string('keepalive_interval', 10)->nullable();
            $table->string('keepalive_window', 10)->nullable();
            $table->timestamp('keepalive_until')->nullable();
            $table->timestamp('keepalive_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['keepalive_interval', 'keepalive_window']));
        Schema::table('billing_services', fn (Blueprint $table) => $table->dropColumn(['keepalive_interval', 'keepalive_window', 'keepalive_until', 'keepalive_notified_at']));
    }
};
