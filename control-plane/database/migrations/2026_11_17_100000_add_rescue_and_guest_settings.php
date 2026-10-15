<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tryb ratunkowy, dysk cloud-init wyłączany przez klienta, swap z kreatora, szablon ratunkowy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->timestamp('rescue_at')->nullable();
            $t->text('rescue_password')->nullable();
            $t->boolean('cloud_init_disabled')->default(false);
            $t->unsignedSmallInteger('swap_mb')->nullable();
        });
        Schema::table('os_templates', fn (Blueprint $t) => $t->boolean('is_rescue')->default(false));
    }

    public function down(): void
    {
        Schema::table('os_templates', fn (Blueprint $t) => $t->dropColumn('is_rescue'));
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['rescue_at', 'rescue_password', 'cloud_init_disabled', 'swap_mb']));
    }
};
