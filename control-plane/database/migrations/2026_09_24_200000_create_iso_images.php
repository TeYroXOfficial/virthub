<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteka obrazów ISO (instalatory, płyty ratunkowe) dla maszyn KVM.
        Schema::create('iso_images', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('filename')->unique();   // nazwa pliku na węzłach
            $table->text('url');
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // Widoczny dla klientów; ukryty = tylko dla personelu (np. narzędzia serwisowe).
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        // Stan pobrania obrazu na każdym węźle KVM — jak template_downloads.
        Schema::create('iso_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iso_image_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued | downloading | ready | failed
            $table->string('agent_job_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['iso_image_id', 'hypervisor_id']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('iso_image_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('boot_from_iso')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('iso_image_id');
            $table->dropColumn('boot_from_iso');
        });
        Schema::dropIfExists('iso_downloads');
        Schema::dropIfExists('iso_images');
    }
};
