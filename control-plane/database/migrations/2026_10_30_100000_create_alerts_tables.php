<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('metric', 40);
            $table->unsignedInteger('threshold')->default(90);
            // Jak długo warunek musi trwać (minuty), zanim alert się otworzy.
            $table->unsignedInteger('duration_minutes')->default(5);
            // Tylko maszyny/hypervisory z tego węzła; null = wszystkie.
            $table->foreignId('hypervisor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_webhook')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 255);
            $table->decimal('value', 12, 2)->nullable();
            $table->decimal('peak', 12, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['alert_rule_id', 'subject_type', 'subject_id', 'resolved_at'], 'alert_incidents_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_incidents');
        Schema::dropIfExists('alert_rules');
    }
};
