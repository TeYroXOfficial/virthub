<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dowolne okresy rozliczeń (np. 3 dni, 2 tygodnie) i okresy jednorazowe.
 * Kategorie ograniczają teraz jednostki okresów (godziny, dni, tygodnie…).
 */
return new class extends Migration
{
    private const UNIT_OF = ['hourly' => 'h', 'daily' => 'd', 'monthly' => 'm', 'quarterly' => 'm', 'semiannually' => 'm', 'annually' => 'y'];

    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->boolean('renews')->default(true); // false = jednorazowo, bez odnowienia
        });
        Schema::table('billing_services', function (Blueprint $table) {
            $table->boolean('renews')->default(true);
            $table->boolean('metered')->default(false); // pobierana z portfela co okres
        });
        DB::table('billing_services')->whereIn('cycle', ['hourly', 'daily'])->update(['metered' => true]);

        DB::table('product_categories')->whereNotNull('allowed_cycles')->get(['id', 'allowed_cycles'])->each(function ($row) {
            $units = collect(json_decode($row->allowed_cycles, true) ?: [])
                ->map(fn ($c) => self::UNIT_OF[$c] ?? $c)->unique()->values()->all();
            DB::table('product_categories')->where('id', $row->id)->update(['allowed_cycles' => $units ? json_encode($units) : null]);
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', fn (Blueprint $table) => $table->dropColumn('renews'));
        Schema::table('billing_services', fn (Blueprint $table) => $table->dropColumn(['renews', 'metered']));
    }
};
