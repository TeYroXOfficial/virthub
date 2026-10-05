<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Po jakim czasie zawieszenia za brak aktywności usługa jest usuwana z serwerów (null = ustawienie globalne). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->string('keepalive_delete_after', 10)->nullable());
        Schema::table('billing_services', fn (Blueprint $table) => $table->string('keepalive_delete_after', 10)->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('keepalive_delete_after'));
        Schema::table('billing_services', fn (Blueprint $table) => $table->dropColumn('keepalive_delete_after'));
    }
};
