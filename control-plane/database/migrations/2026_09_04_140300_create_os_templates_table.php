<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // "Ubuntu 24.04 LTS"
            $table->string('family');               // ubuntu | debian | almalinux | rocky | windows
            $table->string('version');
            // Nazwa pliku obrazu w katalogu szablonów na hypervisorze. Bez ścieżki —
            // układ katalogów jest sprawą agenta, nie panelu.
            $table->string('image_file');
            $table->unsignedInteger('min_disk_gb')->default(10);
            $table->boolean('cloud_init_support')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('icon')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'family']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_templates');
    }
};
