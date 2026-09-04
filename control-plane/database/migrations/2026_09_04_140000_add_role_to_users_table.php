<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin  – pełny dostęp do panelu administracyjnego
            // support – podgląd i akcje serwisowe, bez zmian rozliczeniowych
            // customer – widzi wyłącznie własne maszyny
            $table->string('role')->default('customer')->after('email');
            $table->timestamp('suspended_at')->nullable()->after('role');
            $table->string('two_factor_secret')->nullable()->after('suspended_at');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            // Zewnętrzny identyfikator klienta w systemie billingowym — pozwala
            // provisionować usługę bez znajomości naszego wewnętrznego ID.
            $table->string('billing_reference')->nullable()->unique()->after('two_factor_confirmed_at');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn([
                'role',
                'suspended_at',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'billing_reference',
            ]);
        });
    }
};
