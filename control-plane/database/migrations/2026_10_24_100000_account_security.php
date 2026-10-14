<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Zaszyfrowany sekret nie mieści się pewnie w 255 znakach.
            $table->text('two_factor_secret')->nullable()->change();
            // Skróty kodów zapasowych (JSON, zaszyfrowane).
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            // Ostatni użyty krok TOTP — ten sam kod nie zadziała drugi raz.
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_recovery_codes');
            // Adresy/podsieci, z których wolno używać konta (pusto = bez ograniczeń).
            $table->text('allowed_ips')->nullable()->after('two_factor_last_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_recovery_codes', 'two_factor_last_step', 'allowed_ips']);
        });
    }
};
