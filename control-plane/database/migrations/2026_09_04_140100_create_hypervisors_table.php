<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hypervisors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->string('agent_url');           // np. https://node1.internal:8899
            $table->text('agent_token');           // szyfrowany — sekret HMAC agenta
            $table->text('callback_secret');       // szyfrowany — do weryfikacji raportów agenta
            $table->string('status')->default('offline'); // online | offline | maintenance

            // Pojemność zadeklarowana przez administratora (nie fizyczna) — pozwala
            // zostawić zapas na system hosta i kontrolować overcommit.
            $table->unsignedInteger('cpu_cores_total')->default(0);
            $table->unsignedBigInteger('ram_mb_total')->default(0);
            $table->unsignedInteger('disk_gb_total')->default(0);

            // Suma zasobów przypisanych do VPS-ów. Utrzymywana transakcyjnie przy
            // provisioningu, żeby dwa równoległe zamówienia nie przepełniły node'a.
            $table->unsignedInteger('cpu_cores_used')->default(0);
            $table->unsignedBigInteger('ram_mb_used')->default(0);
            $table->unsignedInteger('disk_gb_used')->default(0);

            // Ostatni heartbeat — jeśli jest stary, node wypada z doboru pod nowe VPS-y.
            $table->timestamp('last_seen_at')->nullable();
            $table->json('last_health')->nullable();

            $table->boolean('accepts_new_servers')->default(true);
            $table->string('bridge')->default('br0');
            $table->timestamps();

            $table->index(['status', 'accepts_new_servers']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypervisors');
    }
};
