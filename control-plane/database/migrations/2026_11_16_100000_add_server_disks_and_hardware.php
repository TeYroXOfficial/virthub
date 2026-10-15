<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dodatkowe dyski maszyn i ustawienia sprzętu wirtualnego. Pola hw_* puste =
 * dziedziczenie: maszyna → szablon (firmware, vTPM) / węzeł (magistrala, I/O,
 * model CPU, cpuset) → wartości domyślne agenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_disks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->unsignedInteger('size_gb');
            $table->timestamps();
            $table->unique(['server_id', 'slot']);
        });
        Schema::table('vps_packages', fn (Blueprint $t) => $t->unsignedInteger('extra_disk_gb')->nullable());
        Schema::table('servers', function (Blueprint $t) {
            $t->string('hw_disk_bus', 8)->nullable();
            $t->string('hw_io_mode', 10)->nullable();
            $t->string('hw_firmware', 4)->nullable();
            $t->boolean('hw_tpm')->nullable();
            $t->string('hw_cpu_model', 40)->nullable();
        });
        Schema::table('hypervisors', function (Blueprint $t) {
            $t->string('hw_disk_bus', 8)->nullable();
            $t->string('hw_io_mode', 10)->nullable();
            $t->string('hw_cpu_model', 40)->nullable();
            $t->string('hw_cpuset', 300)->nullable();
        });
        Schema::table('os_templates', function (Blueprint $t) {
            $t->string('hw_firmware', 4)->nullable();
            $t->boolean('hw_tpm')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('os_templates', fn (Blueprint $t) => $t->dropColumn(['hw_firmware', 'hw_tpm']));
        Schema::table('hypervisors', fn (Blueprint $t) => $t->dropColumn(['hw_disk_bus', 'hw_io_mode', 'hw_cpu_model', 'hw_cpuset']));
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['hw_disk_bus', 'hw_io_mode', 'hw_firmware', 'hw_tpm', 'hw_cpu_model']));
        Schema::table('vps_packages', fn (Blueprint $t) => $t->dropColumn('extra_disk_gb'));
        Schema::dropIfExists('server_disks');
    }
};
