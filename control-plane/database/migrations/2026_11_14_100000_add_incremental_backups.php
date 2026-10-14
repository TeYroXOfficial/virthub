<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kopie przyrostowe: łańcuch kopii (rodzic → dziecko), rodzaj kopii i
 * checkpoint libvirt, od którego liczy się następna; w planie przełącznik i
 * co ile kopii robić pełną.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->string('kind', 16)->default('full');
            $table->string('checkpoint', 64)->nullable();
        });
        Schema::table('backup_plans', function (Blueprint $table) {
            $table->boolean('incremental')->default(false);
            $table->unsignedSmallInteger('full_every')->default(7);
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['kind', 'checkpoint']);
        });
        Schema::table('backup_plans', fn (Blueprint $table) => $table->dropColumn(['incremental', 'full_every']));
    }
};
