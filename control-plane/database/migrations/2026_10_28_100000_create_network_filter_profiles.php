<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_filter_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('anti_spoofing')->default(true);
            $table->boolean('block_router_advertisements')->default(true);
            $table->boolean('block_dhcp_server')->default(true);
            $table->json('rules')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            // Brak = profil domyślny (albo dawne zachowanie, gdy go nie ma).
            $table->foreignId('network_filter_profile_id')->nullable()->after('firewall_locked')
                ->constrained('network_filter_profiles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropConstrainedForeignId('network_filter_profile_id'));
        Schema::dropIfExists('network_filter_profiles');
    }
};
