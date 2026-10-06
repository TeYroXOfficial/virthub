<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Języki panelu: wbudowane (pl, en) i dodane przez administratora. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 60);
            // Język, z którego biorą się teksty jeszcze nieprzetłumaczone (komunikaty walidacji, e-maile).
            $table->string('base', 12)->default('en');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        DB::table('languages')->insert([
            ['code' => 'pl', 'name' => 'Polski', 'base' => 'pl', 'is_enabled' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'en', 'name' => 'English', 'base' => 'en', 'is_enabled' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
