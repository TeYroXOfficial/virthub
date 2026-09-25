<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Miesięczny licznik transferu maszyn i blokada po przekroczeniu limitu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_traffic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->timestamps();
            $table->unique(['server_id', 'period_start']);
        });

        Schema::table('servers', function (Blueprint $table) {
            // Zawieszenie za transfer — zdejmowane automatycznie w nowym okresie,
            // w odróżnieniu od zawieszenia za płatność.
            $table->timestamp('traffic_blocked_at')->nullable()->after('suspension_reason');
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('traffic_blocked_at'));
        Schema::dropIfExists('server_traffic');
    }
};
