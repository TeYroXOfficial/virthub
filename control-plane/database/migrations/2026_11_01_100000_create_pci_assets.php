<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pci_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            // Rodzaj do przydziału z pakietu (gpu, nic, nvme, other).
            $table->string('type', 20)->default('gpu');
            $table->string('pci_address', 12);
            $table->string('vendor_id', 4)->nullable();
            $table->string('device_id', 4)->nullable();
            $table->unsignedInteger('iommu_group')->nullable();
            $table->text('custom_xml')->nullable();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['hypervisor_id', 'pci_address']);
        });

        Schema::table('vps_packages', function (Blueprint $table) {
            // Pakiet z GPU: tyle wolnych urządzeń danego rodzaju trafia do nowej maszyny.
            $table->string('pci_type', 20)->nullable()->after('ipv6_count');
            $table->unsignedTinyInteger('pci_count')->default(0)->after('pci_type');
        });
    }

    public function down(): void
    {
        Schema::table('vps_packages', fn (Blueprint $table) => $table->dropColumn(['pci_type', 'pci_count']));
        Schema::dropIfExists('pci_assets');
    }
};
