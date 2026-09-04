<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            // Jednorazowy bilet rejestracyjny. Przechowywany jako hash — wyciek
            // dumpu bazy nie może dać nikomu gotowego linku instalacyjnego.
            $table->string('enrollment_token_hash')->nullable()->index()->after('callback_secret');
            $table->timestamp('enrollment_expires_at')->nullable()->after('enrollment_token_hash');
            $table->timestamp('enrolled_at')->nullable()->after('enrollment_expires_at');

            // Certyfikat wygenerowany na hypervisorze przy rejestracji.
            // Panel przypina go przy każdym połączeniu (certificate pinning),
            // więc nie potrzebujemy domeny ani Let's Encrypt na każdym węźle,
            // a ruch z hasłami root maszyn i tak idzie szyfrowany.
            $table->text('agent_tls_cert')->nullable()->after('enrolled_at');

            // agent_url jest znany dopiero po rejestracji — węzeł sam zgłasza
            // swój adres, a panel bierze go z adresu źródłowego połączenia.
            $table->string('agent_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hypervisors', function (Blueprint $table) {
            $table->dropColumn([
                'enrollment_token_hash',
                'enrollment_expires_at',
                'enrolled_at',
                'agent_tls_cert',
            ]);
        });
    }
};
