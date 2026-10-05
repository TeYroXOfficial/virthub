<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wbudowany billing: katalog, usługi rozliczane, portfel, faktury i płatności.
 * Kwoty w liczbach całkowitych z dokładnością 0,0001 waluty (App\Domain\Billing\Money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('wallet_balance')->default(0);
            $table->timestamp('wallet_notified_at')->nullable();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('type', 10); // vps | app
            $table->foreignId('vps_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('app_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->json('app_egg_ids')->nullable();       // null = wszystkie aktywne szablony
            $table->json('hypervisor_group_ids')->nullable(); // null = każda publiczna lokalizacja
            $table->bigInteger('setup_fee')->default(0);
            $table->unsignedInteger('stock')->nullable();  // null = bez limitu
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('cycle', 15);
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['product_id', 'cycle']);
        });

        Schema::create('billing_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('cycle', 15);
            $table->bigInteger('amount');
            $table->string('status', 15)->default('pending');
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('app_server_id')->nullable()->constrained()->nullOnDelete();
            $table->json('config')->nullable();
            // Koniec opłaconego okresu (cykle okresowe) albo chwila następnego
            // naliczenia (godzinowe, dzienne).
            $table->timestamp('next_due_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->string('suspend_reason', 20)->nullable(); // unpaid | admin
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_due_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 50)->unique();
            $table->string('type', 10)->default('service'); // service | topup
            $table->string('status', 10)->default('unpaid');
            $table->string('currency', 3);
            $table->unsignedInteger('tax_rate')->default(0); // punkty bazowe: 2300 = 23%
            $table->bigInteger('subtotal');
            $table->bigInteger('tax');
            $table->bigInteger('total');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->json('seller')->nullable();
            $table->json('buyer')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 15); // setup | initial | renewal | topup | other
            $table->string('description', 255);
            $table->bigInteger('amount');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 20); // wallet | stripe | paypal | manual
            $table->string('reference', 191)->nullable();
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->json('meta')->nullable();
            $table->timestamps();
            // Jedna płatność dostawcy zalicza się raz.
            $table->unique(['gateway', 'reference']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15); // topup | payment | usage | refund | adjustment
            $table->bigInteger('amount'); // ze znakiem
            $table->bigInteger('balance_after');
            $table->string('description', 255);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });

        Schema::create('billing_counters', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_counters');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_services');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['wallet_balance', 'wallet_notified_at']);
        });
    }
};
