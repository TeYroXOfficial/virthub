<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Lista uprawnień użytkownika. NULL = domyślny zestaw jego roli
            // (App\Domain\Access\Permissions::defaultsFor) — zmiana domyślnych
            // w kodzie obejmuje wtedy od razu wszystkich bez własnych ustawień.
            $table->json('permissions')->nullable()->after('role');
            // Limit maszyn; NULL = globalny VIRTHUB_SERVERS_PER_CUSTOMER.
            $table->unsignedInteger('max_servers')->nullable()->after('permissions');
            // Pakiety, które użytkownik może zamawiać; NULL = wszystkie aktywne.
            $table->json('allowed_package_ids')->nullable()->after('max_servers');
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'max_servers', 'allowed_package_ids', 'last_login_at']);
        });
    }
};
