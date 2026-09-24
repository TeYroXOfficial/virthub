<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // kvm — maszyny wirtualne (węzeł z VT-x/AMD-V)
        // lxc — kontenery przez Incus (węzeł bez wsparcia sprzętowego)
        //
        // Typ jest na węźle, szablonie i maszynie. Szablon decyduje, na jakim
        // węźle maszyna może stanąć; na maszynie typ zostaje zapisany, bo
        // przebudowa na szablon innego typu jest niemożliwa (kontener nie
        // stanie się maszyną wirtualną na tym samym węźle).
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->string('virtualization')->default('kvm')->after('status');
            $table->index(['virtualization', 'status']);
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->string('virtualization')->default('kvm')->after('family');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('virtualization')->default('kvm')->after('os_template_id');
        });

        // Stan pobrania szablonu kontenera na każdym węźle. Pierwsze pobranie
        // obrazu trwa minuty — robimy je z wyprzedzeniem, żeby klient nie
        // czekał, i pokazujemy administratorowi, na których węzłach się udało.
        Schema::create('template_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('os_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hypervisor_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued'); // queued | downloading | ready | failed
            $table->string('agent_job_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['os_template_id', 'hypervisor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_downloads');

        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('virtualization'));
        Schema::table('os_templates', fn (Blueprint $table) => $table->dropColumn('virtualization'));
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->dropIndex(['virtualization', 'status']);
            $table->dropColumn('virtualization');
        });
    }
};
