<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_pools', fn (Blueprint $t) => $t->unsignedInteger('priority')->default(100));
        Schema::table('hypervisor_groups', fn (Blueprint $t) => $t->string('placement_strategy', 16)->nullable());
        Schema::create('ip_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address', 64)->index();
            $table->unsignedSmallInteger('version');
            $table->unsignedBigInteger('server_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('hostname', 255)->nullable();
            $table->string('user_email', 255)->nullable();
            $table->string('hypervisor_name', 255)->nullable();
            $table->timestamp('assigned_at')->index();
            $table->timestamp('released_at')->nullable();
        });
        Schema::create('server_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('permissions');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['server_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_shares');
        Schema::dropIfExists('ip_assignments');
        Schema::table('hypervisor_groups', fn (Blueprint $t) => $t->dropColumn('placement_strategy'));
        Schema::table('ip_pools', fn (Blueprint $t) => $t->dropColumn('priority'));
    }
};
