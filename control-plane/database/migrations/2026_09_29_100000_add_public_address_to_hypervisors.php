<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adres, pod którym klienci łączą się z portami NAT maszyn na węźle.
 * Puste = wykryty przez agenta (adres wyjścia węzła).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->string('public_address', 255)->nullable()->after('hostname');
        });
    }

    public function down(): void
    {
        Schema::table('hypervisors', fn (Blueprint $t) => $t->dropColumn('public_address'));
    }
};
