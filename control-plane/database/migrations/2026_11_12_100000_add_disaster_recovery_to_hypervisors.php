<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disaster recovery węzła: plan kopii dla maszyn bez własnego planu, cel i
 * częstotliwość zrzutów konfiguracji węzła oraz stan ostatniego zrzutu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->foreignId('dr_backup_plan_id')->nullable()->constrained('backup_plans')->nullOnDelete();
            $table->foreignId('dr_target_id')->nullable()->constrained('backup_targets')->nullOnDelete();
            $table->unsignedSmallInteger('dr_snapshot_hours')->default(24);
            $table->timestamp('dr_last_snapshot_at')->nullable();
            $table->string('dr_last_snapshot_key', 500)->nullable();
            $table->string('dr_last_error', 500)->nullable();
            $table->string('dr_snapshot_job_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dr_backup_plan_id');
            $table->dropConstrainedForeignId('dr_target_id');
            $table->dropColumn(['dr_snapshot_hours', 'dr_last_snapshot_at', 'dr_last_snapshot_key', 'dr_last_error', 'dr_snapshot_job_id']);
        });
    }
};
