<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Postęp pobierania szablonów i obrazów ISO na węzłach — do paska w panelu. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['template_downloads', 'iso_downloads'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedTinyInteger('progress')->nullable()->after('status');
                $t->string('progress_detail', 120)->nullable()->after('progress');
            });
        }
    }

    public function down(): void
    {
        foreach (['template_downloads', 'iso_downloads'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['progress', 'progress_detail']));
        }
    }
};
