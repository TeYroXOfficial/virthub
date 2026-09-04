<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historia zadań wykonanych przez agenta. Osobna od kolejki Horizon:
        // kolejka znika po wykonaniu, a klient i support muszą widzieć, co się
        // działo z maszyną tydzień temu.
        Schema::create('server_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hypervisor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action');       // create | power | rebuild | resize | delete | ...
            $table->string('status')->default('queued'); // queued | running | done | failed
            $table->string('agent_job_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('snapshot'); // snapshot | scheduled
            $table->string('status')->default('creating'); // creating | ready | failed | restoring
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('size_mb')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'name']);
        });

        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('action')->default('accept'); // accept | drop
            $table->string('direction')->default('in');  // in | out
            $table->string('protocol')->default('tcp');  // tcp | udp | icmp | any
            $table->unsignedInteger('port_from')->nullable();
            $table->unsignedInteger('port_to')->nullable();
            $table->string('source')->nullable();        // CIDR
            $table->string('comment')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['server_id', 'position']);
        });

        // Próbki telemetrii z agenta. Trzymane w osobnej tabeli, bo rosną
        // najszybciej ze wszystkiego i podlegają czyszczeniu po retencji.
        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            $table->float('cpu_percent')->default(0);
            // Surowy licznik z libvirt. Procent liczymy z różnicy między
            // próbkami, więc bez zapisanego licznika nie da się go odtworzyć.
            $table->unsignedBigInteger('cpu_time_ns')->default(0);
            $table->unsignedInteger('ram_used_mb')->default(0);
            $table->unsignedBigInteger('disk_read_bytes')->default(0);
            $table->unsignedBigInteger('disk_write_bytes')->default(0);
            $table->unsignedBigInteger('net_rx_bytes')->default(0);
            $table->unsignedBigInteger('net_tx_bytes')->default(0);

            $table->index(['server_id', 'sampled_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable(); // zachowane, gdy konto zniknie
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('server_metrics');
        Schema::dropIfExists('firewall_rules');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('server_jobs');
    }
};
