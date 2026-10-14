<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 10); // local | s3 | sftp
            $table->text('config'); // zaszyfrowany JSON
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('backup_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('backup_target_id')->constrained()->restrictOnDelete();
            // co ile godzin (24 = codziennie, 168 = co tydzień)
            $table->unsignedSmallInteger('interval_hours')->default(24);
            // preferowana godzina startu (UTC) dla planów co ≥ 24 h
            $table->unsignedTinyInteger('hour')->default(3);
            $table->unsignedSmallInteger('retention')->default(7);
            $table->boolean('compress')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('backup_plan_id')->nullable()->after('resource_pack_id')->constrained()->nullOnDelete();
        });
        Schema::table('vps_packages', function (Blueprint $table) {
            $table->foreignId('backup_plan_id')->nullable()->after('pci_count')->constrained()->nullOnDelete();
        });
        Schema::table('backups', function (Blueprint $table) {
            $table->foreignId('backup_target_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->string('remote_key', 500)->nullable()->after('storage_path');
            $table->string('sha256', 64)->nullable()->after('remote_key');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('size_mb');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backup_target_id');
            $table->dropColumn(['remote_key', 'sha256', 'size_bytes']);
        });
        Schema::table('vps_packages', fn (Blueprint $table) => $table->dropConstrainedForeignId('backup_plan_id'));
        Schema::table('servers', fn (Blueprint $table) => $table->dropConstrainedForeignId('backup_plan_id'));
        Schema::dropIfExists('backup_plans');
        Schema::dropIfExists('backup_targets');
    }
};
