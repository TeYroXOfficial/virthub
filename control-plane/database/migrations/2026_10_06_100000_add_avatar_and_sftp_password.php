<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
        });
        Schema::table('app_servers', function (Blueprint $table) {
            // Osobne hasło SFTP aplikacji (hash). Brak = logowanie hasłem do panelu.
            $table->string('sftp_password')->nullable()->after('environment');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('avatar_path'));
        Schema::table('app_servers', fn (Blueprint $t) => $t->dropColumn('sftp_password'));
    }
};
