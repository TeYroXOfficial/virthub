<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Node i pakiet zostają w bazie nawet po usunięciu definicji — historia
            // maszyny musi przetrwać porządki w katalogu ofert.
            $table->foreignId('hypervisor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vps_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('os_template_id')->nullable()->constrained()->nullOnDelete();

            $table->string('hostname');
            $table->string('label')->nullable(); // nazwa własna nadana przez klienta

            // building | running | stopped | suspended | rebuilding | resizing | deleting | error
            $table->string('state')->default('building');
            $table->unsignedTinyInteger('build_progress')->default(0);
            $table->text('state_message')->nullable(); // powód błędu widoczny dla klienta

            // Parametry skopiowane z pakietu w momencie zamówienia. Zmiana pakietu
            // w cenniku nie może po cichu zmienić zasobów działającej maszyny.
            $table->unsignedInteger('vcpu');
            $table->unsignedInteger('ram_mb');
            $table->unsignedInteger('disk_gb');
            $table->unsignedInteger('bandwidth_gb');

            // Identyfikatory po stronie hypervisora.
            $table->uuid('agent_uuid')->nullable()->unique();
            $table->string('mac_address')->nullable();
            $table->unsignedInteger('vnc_port')->nullable();
            $table->text('vnc_password')->nullable();  // szyfrowane
            $table->text('root_password')->nullable(); // szyfrowane, kasowane po odczytaniu

            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('billing_reference')->nullable()->index();

            $table->timestamps();
            $table->softDeletes(); // usunięta maszyna zostaje w historii i audycie

            $table->index(['user_id', 'state']);
            $table->index('hypervisor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
