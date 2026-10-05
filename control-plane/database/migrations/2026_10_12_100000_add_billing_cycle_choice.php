<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Okresy rozliczeń dozwolone w kategorii i limit sztuk produktu na klienta (np. darmowe usługi). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->json('allowed_cycles')->nullable(); // null = wszystkie
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('per_user_limit')->nullable(); // null = bez limitu
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', fn (Blueprint $table) => $table->dropColumn('allowed_cycles'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('per_user_limit'));
    }
};
