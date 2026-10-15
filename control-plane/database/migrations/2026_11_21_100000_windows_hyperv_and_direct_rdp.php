<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['servers', 'hypervisors', 'os_templates'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('hw_hyperv', 12)->nullable());
        }
        Schema::table('os_templates', fn (Blueprint $t) => $t->boolean('direct_rdp')->default(false));
    }

    public function down(): void
    {
        Schema::table('os_templates', fn (Blueprint $t) => $t->dropColumn('direct_rdp'));
        foreach (['servers', 'hypervisors', 'os_templates'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('hw_hyperv'));
        }
    }
};
