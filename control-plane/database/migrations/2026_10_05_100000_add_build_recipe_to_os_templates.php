<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            // Szablon budowany Packerem na węzłach (Windows Server) i jego ustawienia (ISO, wersja ewaluacyjna).
            $table->string('build_recipe', 40)->nullable()->after('checksum_url');
            $table->json('build_options')->nullable()->after('build_recipe');
        });
    }

    public function down(): void
    {
        Schema::table('os_templates', fn (Blueprint $t) => $t->dropColumn(['build_recipe', 'build_options']));
    }
};
