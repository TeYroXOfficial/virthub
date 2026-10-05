<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nadpisania szablonów e-mail (domyślne treści są w kodzie: App\Domain\Mail\EmailTemplates). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->string('locale', 5);
            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
