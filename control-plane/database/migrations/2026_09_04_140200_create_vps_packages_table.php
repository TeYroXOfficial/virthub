<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vps_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // stabilny identyfikator dla systemu billingowego
            $table->text('description')->nullable();

            $table->unsignedInteger('vcpu');
            $table->unsignedInteger('ram_mb');
            $table->unsignedInteger('disk_gb');
            $table->unsignedInteger('bandwidth_gb')->default(1000);
            $table->unsignedInteger('ip_count')->default(1);

            // Cena jest tu wyłącznie informacyjnie — rozliczaniem zajmuje się
            // system billingowy, panel nie wystawia faktur.
            $table->unsignedInteger('price_hint_cents')->nullable();
            $table->string('currency', 3)->default('PLN');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vps_packages');
    }
};
