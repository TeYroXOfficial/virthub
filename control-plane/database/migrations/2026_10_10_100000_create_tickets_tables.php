<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Zgłoszenia klientów: działy, wątki, załączniki, gotowe odpowiedzi personelu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Dwa działy na start — administrator zmienia je w Wsparcie → Działy.
        DB::table('ticket_departments')->insert([
            ['name' => 'Wsparcie techniczne', 'description' => 'Problemy z maszynami, aplikacjami i siecią.', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Płatności i oferta', 'description' => 'Faktury, płatności, zmiana pakietu, pytania przed zakupem.', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 150);
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('medium');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // Usługa, której dotyczy zgłoszenie: maszyna albo aplikacja klienta.
            $table->string('service_type', 10)->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->string('last_reply_by', 10)->nullable(); // customer | staff
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_reply_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_staff')->default(false);
            // Notatka wewnętrzna — widzi ją tylko personel.
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size');
            $table->timestamps();
        });

        Schema::create('ticket_canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_canned_responses');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_departments');
    }
};
