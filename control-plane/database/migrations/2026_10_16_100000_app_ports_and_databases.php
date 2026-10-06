<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Zarządzanie portami aplikacji i bazy danych MySQL/MariaDB dla aplikacji. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_allocations', function (Blueprint $table) {
            $table->string('notes', 60)->nullable();
        });
        Schema::table('app_servers', function (Blueprint $table) {
            $table->unsignedInteger('port_limit')->nullable();     // null = limit planu
            $table->unsignedInteger('database_limit')->nullable(); // null = limit planu
        });
        Schema::table('app_plans', function (Blueprint $table) {
            $table->unsignedInteger('databases')->default(0);
        });

        Schema::create('database_hosts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('host', 255);            // adres, pod którym łączy się panel
            $table->unsignedInteger('port')->default(3306);
            $table->string('public_host', 255)->nullable(); // adres podawany klientom
            $table->string('username', 64);
            $table->text('password');                // zaszyfrowane APP_KEY
            $table->foreignId('hypervisor_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('max_databases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_host_id')->constrained()->restrictOnDelete();
            $table->string('database', 64);
            $table->string('username', 32);
            $table->text('password');               // zaszyfrowane APP_KEY
            $table->string('remote', 64)->default('%');
            $table->timestamps();
            $table->unique(['database_host_id', 'database']);
            $table->unique(['database_host_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_databases');
        Schema::dropIfExists('database_hosts');
        Schema::table('app_plans', fn (Blueprint $table) => $table->dropColumn('databases'));
        Schema::table('app_servers', fn (Blueprint $table) => $table->dropColumn(['port_limit', 'database_limit']));
        Schema::table('app_allocations', fn (Blueprint $table) => $table->dropColumn('notes'));
    }
};
