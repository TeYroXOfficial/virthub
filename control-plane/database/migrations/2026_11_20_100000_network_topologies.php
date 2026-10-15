<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sieci prywatne rozpięte na grupę węzłów (VXLAN po adresach węzłów w sieci
 * wewnętrznej) i reguły zapory przypisane do konkretnej karty maszyny.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypervisors', fn (Blueprint $t) => $t->string('vxlan_address', 45)->nullable());
        Schema::table('private_networks', fn (Blueprint $t) => $t->foreignId('hypervisor_group_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('firewall_rules', fn (Blueprint $t) => $t->string('interface', 8)->default('primary'));
    }

    public function down(): void
    {
        Schema::table('firewall_rules', fn (Blueprint $t) => $t->dropColumn('interface'));
        Schema::table('private_networks', fn (Blueprint $t) => $t->dropConstrainedForeignId('hypervisor_group_id'));
        Schema::table('hypervisors', fn (Blueprint $t) => $t->dropColumn('vxlan_address'));
    }
};
