<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limit procesora (procent jednego rdzenia, jak cpulimit w Proxmoksie ×100)
 * oraz przekierowania portów NAT ustawiane przez klienta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vps_packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('cpu_limit_percent')->nullable()->after('vcpu');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedSmallInteger('cpu_limit_percent')->nullable()->after('vcpu');
        });

        Schema::create('nat_port_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ip_address_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('external_port');
            $table->unsignedSmallInteger('internal_port');
            $table->string('label', 40)->nullable();
            $table->timestamps();

            $table->unique(['ip_address_id', 'external_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nat_port_forwards');
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('cpu_limit_percent'));
        Schema::table('vps_packages', fn (Blueprint $t) => $t->dropColumn('cpu_limit_percent'));
    }
};
