<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rezygnacja z usługi z wybranym terminem (za dzień, za dwa dni). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_services', fn (Blueprint $t) => $t->timestamp('cancel_at')->nullable()->after('cancel_at_period_end'));
    }

    public function down(): void
    {
        Schema::table('billing_services', fn (Blueprint $t) => $t->dropColumn('cancel_at'));
    }
};
