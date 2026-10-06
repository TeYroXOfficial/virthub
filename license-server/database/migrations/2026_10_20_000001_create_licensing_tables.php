<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('owner_name', 150);
            $table->string('owner_email', 190)->nullable();
            // Domena panelu — przypinana przy pierwszej aktywacji, zmienia ją administrator.
            $table->string('domain', 253)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('panel_version', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('price', 60)->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('addon_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->string('version', 40);
            $table->string('sha256', 64);
            $table->string('signature', 120);
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->text('changelog')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->unique(['addon_id', 'version']);
        });

        Schema::create('addon_license', function (Blueprint $table) {
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->primary(['license_id', 'addon_id']);
        });

        Schema::create('license_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('addon_license');
        Schema::dropIfExists('addon_versions');
        Schema::dropIfExists('addons');
        Schema::dropIfExists('licenses');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
