<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System odczytany z wnętrza maszyny (może różnić się od szablonu, np. po
 * instalacji z ISO) oraz model procesora węzła pokazywany klientom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('guest_os_id', 40)->nullable()->after('os_template_id');
            $table->string('guest_os_name', 120)->nullable()->after('guest_os_id');
            $table->string('guest_os_version', 40)->nullable()->after('guest_os_name');
            $table->timestamp('guest_os_checked_at')->nullable()->after('guest_os_version');
        });

        Schema::table('hypervisors', function (Blueprint $table) {
            $table->string('cpu_model', 120)->nullable()->after('cpu_cores_total');
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['guest_os_id', 'guest_os_name', 'guest_os_version', 'guest_os_checked_at']));
        Schema::table('hypervisors', fn (Blueprint $t) => $t->dropColumn('cpu_model'));
    }
};
