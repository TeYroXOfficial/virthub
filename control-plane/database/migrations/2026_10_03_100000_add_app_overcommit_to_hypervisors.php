<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            // Ile procent pamięci węzła można przydzielić aplikacjom (serwery gier rzadko zużywają cały limit).
            $table->unsignedSmallInteger('app_memory_overcommit')->default(100)->after('app_port_end');
        });
    }

    public function down(): void
    {
        Schema::table('hypervisors', fn (Blueprint $t) => $t->dropColumn('app_memory_overcommit'));
    }
};
