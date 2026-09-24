<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grupa węzłów — np. „Warszawa, wspólny VLAN". Pula przypisana do
        // grupy obsługuje wszystkie jej węzły: adres przypisuje się do węzła
        // dopiero razem z maszyną, więc sieć dostawcy musi dostarczać tę
        // podsieć do każdego węzła grupy (wspólny segment L2).
        Schema::create('hypervisor_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::table('hypervisors', function (Blueprint $table) {
            $table->foreignId('hypervisor_group_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('ip_pools', function (Blueprint $table) {
            // Pula należy do węzła albo do grupy — dokładnie jedno z dwóch.
            $table->foreignId('hypervisor_id')->nullable()->change();
            $table->foreignId('hypervisor_group_id')->nullable()->after('hypervisor_id')
                ->constrained()->cascadeOnDelete();

            // public — adresy na mostku z kartą fizyczną, routowane przez dostawcę
            // nat    — adresy prywatne za NAT-em węzła, dostęp z zewnątrz przez porty
            $table->string('type')->default('public')->after('name');

            // Zakres przydziału. IPv4 rozwija się przy imporcie na pojedyncze
            // wiersze, IPv6 nie da się rozwinąć (/64 to 2^64 adresów), więc
            // przydzielamy leniwie od początku zakresu i pamiętamy kursor.
            $table->string('range_from')->nullable()->after('prefix');
            $table->string('range_to')->nullable()->after('range_from');
            $table->unsignedBigInteger('next_offset')->default(0)->after('range_to');

            // NAT: publiczny adres wyjścia (brak = adres interfejsu węzła) i blok
            // portów. Maszyna dostaje porty wyliczone z pozycji adresu w puli,
            // więc przydział jest deterministyczny i nie wymaga osobnej tabeli.
            $table->string('nat_public_address')->nullable();
            $table->unsignedInteger('nat_port_start')->nullable();
            $table->unsignedSmallInteger('nat_ports_per_server')->nullable();

            $table->index(['type', 'version']);
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            // Adres z puli grupy nie należy do żadnego węzła, dopóki nie
            // dostanie go maszyna.
            $table->foreignId('hypervisor_id')->nullable()->change();

            // Przestrzeń unikalności adresu. Publiczne adresy są unikalne
            // globalnie („public") — to nadal jedyna gwarancja, że dwóch
            // klientów nie dostanie tego samego IP. Adresy prywatne NAT są
            // unikalne tylko w swojej puli („nat:{id}"), bo każdy węzeł może
            // mieć za NAT-em tę samą sieć 10.0.0.0/24.
            $table->string('scope_key')->default('public')->after('address');
        });

        DB::table('ip_addresses')->update(['scope_key' => 'public']);

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropUnique(['address']);
            $table->unique(['scope_key', 'address']);
        });

        Schema::table('vps_packages', function (Blueprint $table) {
            $table->string('network_type')->default('public')->after('ip_count');
            $table->unsignedInteger('ipv6_count')->default(0)->after('network_type');
        });
    }

    public function down(): void
    {
        Schema::table('vps_packages', function (Blueprint $table) {
            $table->dropColumn(['network_type', 'ipv6_count']);
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropUnique(['scope_key', 'address']);
            $table->dropColumn('scope_key');
            $table->unique('address');
        });

        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropIndex(['type', 'version']);
            $table->dropConstrainedForeignId('hypervisor_group_id');
            $table->dropColumn([
                'type', 'range_from', 'range_to', 'next_offset',
                'nat_public_address', 'nat_port_start', 'nat_ports_per_server',
            ]);
        });

        Schema::table('hypervisors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hypervisor_group_id');
        });

        Schema::dropIfExists('hypervisor_groups');
    }
};
