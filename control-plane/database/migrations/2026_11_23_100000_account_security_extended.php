<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('credential_id', 1400)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('transports', 200)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        Schema::table('users', fn (Blueprint $t) => $t->boolean('session_lock')->default(false));
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->unsignedInteger('rate_limit')->nullable());
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn('rate_limit'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('session_lock'));
        Schema::dropIfExists('webauthn_credentials');
    }
};
