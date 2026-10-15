<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup Manager: szyfrowanie na celu, tryb planu (pełna / przyrostowa /
 * różnicowa), retencja GFS, dni tygodnia w harmonogramie, informacja
 * o spójności (fsfreeze) i szyfrowaniu każdej kopii.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_targets', fn (Blueprint $t) => $t->text('encryption_key')->nullable());
        Schema::table('backup_plans', function (Blueprint $t) {
            $t->string('mode', 12)->nullable();
            $t->unsignedSmallInteger('keep_daily')->nullable();
            $t->unsignedSmallInteger('keep_weekly')->nullable();
            $t->unsignedSmallInteger('keep_monthly')->nullable();
            $t->json('days')->nullable();
        });
        Schema::table('backups', function (Blueprint $t) {
            $t->boolean('fs_frozen')->nullable();
            $t->boolean('encrypted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('backups', fn (Blueprint $t) => $t->dropColumn(['fs_frozen', 'encrypted']));
        Schema::table('backup_plans', fn (Blueprint $t) => $t->dropColumn(['mode', 'keep_daily', 'keep_weekly', 'keep_monthly', 'days']));
        Schema::table('backup_targets', fn (Blueprint $t) => $t->dropColumn('encryption_key'));
    }
};
