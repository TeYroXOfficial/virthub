<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grupy systemów (Ubuntu → 22.04, 24.04…) oraz dodatkowe ustawienia węzłów
 * i grup węzłów: limit maszyn, notatki, lokalizacja widoczna przy zamówieniu.
 */
return new class extends Migration
{
    /** Nazwy grup dla rodzin, które istniały przed wprowadzeniem grup. */
    private const FAMILY_NAMES = [
        'ubuntu' => 'Ubuntu', 'debian' => 'Debian', 'almalinux' => 'AlmaLinux', 'rocky' => 'Rocky Linux',
        'fedora' => 'Fedora', 'windows' => 'Windows', 'centos' => 'CentOS', 'alpine' => 'Alpine Linux',
        'arch' => 'Arch Linux', 'opensuse' => 'openSUSE',
    ];

    public function up(): void
    {
        Schema::create('os_template_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('family', 32)->default('linux');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->foreignId('os_template_group_id')->nullable()->after('id')
                ->constrained('os_template_groups')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0)->after('version');
        });

        // Istniejące szablony trafiają do grup według rodziny — po aktualizacji
        // katalog wygląda tak samo, tylko wersje są zebrane pod jednym systemem.
        $order = 0;
        foreach (DB::table('os_templates')->distinct()->orderBy('family')->pluck('family') as $family) {
            $groupId = DB::table('os_template_groups')->insertGetId([
                'name' => self::FAMILY_NAMES[$family] ?? ucfirst((string) $family),
                'family' => $family,
                'sort_order' => $order += 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('os_templates')->where('family', $family)->update(['os_template_group_id' => $groupId]);
        }

        Schema::table('hypervisors', function (Blueprint $table) {
            $table->unsignedInteger('max_servers')->nullable()->after('disk_gb_total');
            $table->text('notes')->nullable()->after('bridge');
        });

        Schema::table('hypervisor_groups', function (Blueprint $table) {
            $table->string('location', 100)->nullable()->after('description');
            $table->boolean('is_public')->default(false)->after('location');
            $table->boolean('accepts_new_servers')->default(true)->after('is_public');
            $table->unsignedInteger('sort_order')->default(0)->after('accepts_new_servers');
        });
    }

    public function down(): void
    {
        Schema::table('hypervisor_groups', function (Blueprint $table) {
            $table->dropColumn(['location', 'is_public', 'accepts_new_servers', 'sort_order']);
        });
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->dropColumn(['max_servers', 'notes']);
        });
        Schema::table('os_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('os_template_group_id');
            $table->dropColumn('sort_order');
        });
        Schema::dropIfExists('os_template_groups');
    }
};
