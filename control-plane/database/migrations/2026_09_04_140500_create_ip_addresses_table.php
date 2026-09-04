<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('cidr');                 // 203.0.113.0/24
            $table->unsignedTinyInteger('version')->default(4);
            $table->string('gateway');
            $table->unsignedTinyInteger('prefix');  // maska przypisywana gościowi
            $table->json('nameservers')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();

            $table->string('address');
            $table->unsignedTinyInteger('version')->default(4);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_reserved')->default(false); // np. brama, adres hosta
            $table->string('rdns')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            // Ten sam adres nie może istnieć dwa razy — to jedyna gwarancja, że
            // dwóch klientów nie dostanie tego samego IP.
            $table->unique('address');
            $table->index(['hypervisor_id', 'server_id', 'is_reserved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('ip_pools');
    }
};
