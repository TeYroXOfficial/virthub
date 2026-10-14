<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Magazyny danych: katalog z dyskami qcow2 albo pula Ceph RBD, przypięte do
 * hypervisorów (z ostatnim odczytem pojemności) i opcjonalnie do pakietów.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datastores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 16);
            $table->boolean('thin')->default(true);
            $table->text('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('datastore_hypervisor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('datastore_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_gb')->nullable();
            $table->unsignedInteger('free_gb')->nullable();
            $table->boolean('ok')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unique(['datastore_id', 'hypervisor_id']);
        });

        Schema::table('vps_packages', function (Blueprint $table) {
            $table->foreignId('datastore_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('datastore_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropConstrainedForeignId('datastore_id'));
        Schema::table('vps_packages', fn (Blueprint $table) => $table->dropConstrainedForeignId('datastore_id'));
        Schema::dropIfExists('datastore_hypervisor');
        Schema::dropIfExists('datastores');
    }
};
