<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailouts', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 200);
            $table->longText('body');
            $table->json('audience');
            // Ogłoszenia serwisowe (awaria, prace) idą też do wypisanych z newslettera.
            $table->boolean('respect_opt_out')->default(true);
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailout_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 190);
            $table->string('status', 16)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['mailout_id', 'user_id']);
            $table->index(['mailout_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mailout_opt_out')->default(false)->after('allowed_ips');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('mailout_opt_out'));
        Schema::dropIfExists('mailout_recipients');
        Schema::dropIfExists('mailouts');
    }
};
