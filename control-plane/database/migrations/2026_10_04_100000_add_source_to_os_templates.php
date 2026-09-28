<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_templates', function (Blueprint $table) {
            // Szablon KVM z katalogu: węzły pobierają obraz same (zamiast wgrywania ręcznie).
            $table->string('source_url', 2000)->nullable()->after('image_file');
            $table->string('checksum_url', 2000)->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('os_templates', fn (Blueprint $t) => $t->dropColumn(['source_url', 'checksum_url']));
    }
};
