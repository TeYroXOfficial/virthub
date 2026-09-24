<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liczniki dysku i sieci są narastające — wykres potrzebuje szybkości.
        // Liczymy ją raz, przy zapisie próbki (różnica do poprzedniej), zamiast
        // przy każdym odczycie wykresu, i przechowujemy w bajtach na sekundę.
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->unsignedInteger('ram_total_mb')->default(0)->after('ram_used_mb');
            $table->unsignedBigInteger('disk_read_bps')->default(0)->after('disk_write_bytes');
            $table->unsignedBigInteger('disk_write_bps')->default(0)->after('disk_read_bps');
            $table->unsignedBigInteger('net_rx_bps')->default(0)->after('net_tx_bytes');
            $table->unsignedBigInteger('net_tx_bps')->default(0)->after('net_rx_bps');
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn(['ram_total_mb', 'disk_read_bps', 'disk_write_bps', 'net_rx_bps', 'net_tx_bps']);
        });
    }
};
