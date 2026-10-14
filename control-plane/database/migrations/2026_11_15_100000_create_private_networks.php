<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sieci prywatne klienta (izolowany mostek na węźle) i dodatkowe karty maszyn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('cidr', 18);
            $table->timestamps();
            $table->index(['user_id', 'hypervisor_id']);
        });
        Schema::create('server_nics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('private_network_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('address', 15)->nullable();
            $table->string('mac', 17)->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'slot']);
            $table->unique(['server_id', 'private_network_id']);
            $table->unique(['private_network_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_nics');
        Schema::dropIfExists('private_networks');
    }
};
