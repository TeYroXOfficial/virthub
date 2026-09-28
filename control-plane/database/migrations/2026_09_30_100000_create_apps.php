<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aplikacje: serwery gier, boty i inne usługi w kontenerach Dockera na węzłach
 * (odpowiednik Pterodactyla). Szablonem jest egg w formacie Pterodactyla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->boolean('apps_enabled')->default(false)->after('accepts_new_servers');
            $table->unsignedInteger('app_port_start')->nullable()->after('apps_enabled');
            $table->unsignedInteger('app_port_end')->nullable()->after('app_port_start');
        });

        Schema::create('app_eggs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 20)->default('game');
            $table->text('description')->nullable();
            $table->string('author', 190)->nullable();
            $table->json('docker_images');
            $table->text('startup');
            $table->string('stop_command', 200)->default('^C');
            $table->string('startup_done', 200)->nullable();
            $table->json('config_files')->nullable();
            $table->string('install_image', 255)->nullable();
            $table->string('install_entrypoint', 64)->nullable();
            $table->longText('install_script')->nullable();
            $table->json('variables')->nullable();
            $table->json('features')->nullable();
            $table->string('source', 20)->default('import');
            $table->string('builtin_key', 60)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('memory_mb');
            $table->unsignedSmallInteger('cpu_percent')->default(0);
            $table->unsignedInteger('disk_mb');
            $table->unsignedTinyInteger('ports')->default(1);
            $table->unsignedInteger('price_hint_cents')->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_servers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('hypervisor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('app_egg_id')->constrained()->restrictOnDelete();
            $table->foreignId('app_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->string('docker_image', 255);
            $table->json('environment')->nullable();
            $table->unsignedInteger('memory_mb');
            $table->unsignedSmallInteger('cpu_percent')->default(0);
            $table->unsignedInteger('disk_mb');
            $table->string('status', 20)->default('installing');
            $table->text('status_message')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('app_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('port');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['hypervisor_id', 'port']);
        });

        Schema::create('app_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('status', 20)->default('queued');
            $table->string('agent_job_id', 64)->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_jobs');
        Schema::dropIfExists('app_allocations');
        Schema::dropIfExists('app_servers');
        Schema::dropIfExists('app_plans');
        Schema::dropIfExists('app_eggs');
        Schema::table('hypervisors', fn (Blueprint $t) => $t->dropColumn(['apps_enabled', 'app_port_start', 'app_port_end']));
    }
};
