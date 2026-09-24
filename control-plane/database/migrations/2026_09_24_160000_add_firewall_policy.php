<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Zapora jako całość: czy działa i co dzieje się z ruchem, którego
            // nie opisuje żadna reguła.
            $table->boolean('firewall_enabled')->default(false);
            $table->string('firewall_inbound')->default('accept');  // accept | drop
            $table->string('firewall_outbound')->default('accept'); // accept | drop
            // Administrator może zablokować klientowi zmiany zapory (np. po
            // nadużyciu) — reguły dalej działają, klient je tylko widzi.
            $table->boolean('firewall_locked')->default(false);
        });

        Schema::table('firewall_rules', function (Blueprint $table) {
            // customer — reguła klienta; admin — nadana przez personel,
            // sprawdzana przed regułami klienta i dla niego nieusuwalna.
            $table->string('managed_by')->default('customer')->after('server_id');
            $table->boolean('enabled')->default(true)->after('managed_by');
        });

        // Dotychczas reguły działały zawsze. Maszyny, które już je mają,
        // dostają włączoną zaporę z otwartą polityką — zachowanie bez zmian.
        DB::table('servers')
            ->whereIn('id', DB::table('firewall_rules')->select('server_id'))
            ->update(['firewall_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table) {
            $table->dropColumn(['managed_by', 'enabled']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['firewall_enabled', 'firewall_inbound', 'firewall_outbound', 'firewall_locked']);
        });
    }
};
