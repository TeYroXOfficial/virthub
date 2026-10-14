<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            // Tagowany VLAN na łączu węzła (null = sieć bez tagu na głównym mostku).
            $table->unsignedSmallInteger('vlan_id')->nullable()->after('type');
            // Podsieć routowana do węzła: adresy /32 (/128) z bramą on-link, bez L2 u dostawcy.
            $table->boolean('routed')->default(false)->after('vlan_id');
        });
    }

    public function down(): void
    {
        Schema::table('ip_pools', fn (Blueprint $table) => $table->dropColumn(['vlan_id', 'routed']));
    }
};
