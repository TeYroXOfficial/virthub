<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $t) {
            $t->text('bearer_token')->nullable();
            $t->string('format', 16)->default('virthub');
            $t->text('options')->nullable();
        });
        // Dodatki rozliczane godzinowo i zmienne zasoby przy zamówieniu.
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('addon_ip_hour')->default(0);
            $t->unsignedInteger('addon_pci_hour')->default(0);
            $t->text('resource_options')->nullable();
        });
        // Wspólny transfer pakietu zasobów i stawka za dodatkowe adresy.
        Schema::table('resource_packs', function (Blueprint $t) {
            $t->unsignedInteger('traffic_gb')->nullable();
            $t->unsignedInteger('price_ip_hour')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('resource_packs', fn (Blueprint $t) => $t->dropColumn(['traffic_gb', 'price_ip_hour']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['addon_ip_hour', 'addon_pci_hour', 'resource_options']));
        Schema::table('webhooks', fn (Blueprint $t) => $t->dropColumn(['bearer_token', 'format', 'options']));
    }
};
