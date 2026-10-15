<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polityka transferu (kierunek liczenia, akcja po limicie, prędkość dławienia)
 * na maszynie, w pakiecie i na węźle — puste = dziedziczenie. Pakiety
 * transferu dokupione na okres oraz stała prędkość portu z pakietu.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['servers', 'vps_packages', 'hypervisors'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('traffic_counting', 5)->nullable();
                $t->string('traffic_limit_action', 8)->nullable();
                $t->unsignedInteger('traffic_throttle_mbps')->nullable();
            });
        }
        Schema::table('servers', function (Blueprint $t) {
            $t->timestamp('traffic_throttled_at')->nullable();
            $t->unsignedInteger('port_speed_mbps')->nullable();
        });
        Schema::table('vps_packages', fn (Blueprint $t) => $t->unsignedInteger('port_speed_mbps')->nullable());
        Schema::create('traffic_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedInteger('gb');
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['server_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_blocks');
        Schema::table('vps_packages', fn (Blueprint $t) => $t->dropColumn('port_speed_mbps'));
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['traffic_throttled_at', 'port_speed_mbps']));
        foreach (['servers', 'vps_packages', 'hypervisors'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['traffic_counting', 'traffic_limit_action', 'traffic_throttle_mbps']));
        }
    }
};
