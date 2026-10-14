<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Budżet: suma zasobów wszystkich maszyn z pakietu.
            $table->unsignedSmallInteger('vcpu');
            $table->unsignedInteger('ram_mb');
            $table->unsignedInteger('disk_gb');
            $table->unsignedSmallInteger('max_servers')->default(5);
            // Sieć, adresy i transfer nowych maszyn — z pakietu VPS.
            $table->foreignId('vps_package_id')->constrained()->restrictOnDelete();
            // Stawki godzinowe w groszach (null = bez opłat godzinowych).
            $table->unsignedInteger('price_vcpu_hour')->nullable();
            $table->unsignedInteger('price_ram_gb_hour')->nullable();
            $table->unsignedInteger('price_disk_gb_hour')->nullable();
            $table->timestamp('billed_until')->nullable();
            // Zawieszony za brak środków — maszyny wracają po doładowaniu.
            $table->timestamp('suspended_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('resource_pack_id')->nullable()->after('vps_package_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropConstrainedForeignId('resource_pack_id'));
        Schema::dropIfExists('resource_packs');
    }
};
