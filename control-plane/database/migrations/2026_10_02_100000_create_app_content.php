<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instalator modpacków i pluginów: co działa na serwerze (platforma, wersja
 * gry, modpack), zainstalowane pluginy/mody i dane zadań instalacji treści.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_servers', function (Blueprint $table) {
            // {platform, mc, loader_version, modpack: {source, id, version_id, name, version, icon}}
            $table->json('minecraft')->nullable()->after('environment');
        });

        Schema::table('app_jobs', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('action');
        });

        Schema::create('app_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_server_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);            // plugin | mod
            $table->string('source', 20);          // modrinth | hangar | curseforge
            $table->string('project_id', 100);
            $table->string('version_id', 100);
            $table->string('name', 200);
            $table->string('version', 100)->nullable();
            $table->string('filename', 255);
            $table->string('icon', 500)->nullable();
            $table->string('game_version', 30)->nullable();
            $table->boolean('dependency')->default(false);
            $table->timestamps();

            $table->unique(['app_server_id', 'source', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_addons');
        Schema::table('app_jobs', fn (Blueprint $table) => $table->dropColumn('payload'));
        Schema::table('app_servers', fn (Blueprint $table) => $table->dropColumn('minecraft'));
    }
};
