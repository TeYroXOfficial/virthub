<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reselling: konta dostawców zewnętrznych (sterowniki z addonów) i maszyny u nich. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 40);
            $table->string('name', 100);
            $table->text('credentials'); // JSON szyfrowany APP_KEY
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('external_servers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_account_id')->constrained()->restrictOnDelete();
            $table->string('remote_id', 100)->nullable();
            $table->string('name', 100);
            $table->string('hostname', 253);
            $table->string('location', 100);
            $table->string('plan', 100);
            $table->unsignedInteger('cpu')->nullable();
            $table->unsignedInteger('ram_mb')->nullable();
            $table->unsignedInteger('disk_gb')->nullable();
            $table->string('image', 100);
            $table->string('image_name', 150)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('ipv4', 45)->nullable();
            $table->string('ipv6', 45)->nullable();
            $table->text('password')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['provider_account_id', 'remote_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('provider_account_id')->nullable()->after('app_plan_id')->constrained()->nullOnDelete();
            $table->json('external_config')->nullable()->after('provider_account_id');
        });

        Schema::table('billing_services', function (Blueprint $table) {
            $table->foreignId('external_server_id')->nullable()->after('app_server_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_services', fn (Blueprint $t) => $t->dropConstrainedForeignId('external_server_id'));
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('provider_account_id');
            $t->dropColumn('external_config');
        });
        Schema::dropIfExists('external_servers');
        Schema::dropIfExists('provider_accounts');
    }
};
