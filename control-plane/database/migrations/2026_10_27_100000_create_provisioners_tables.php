<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 41)->unique();
            $table->string('description', 500)->nullable();
            $table->mediumText('content');
            $table->string('path', 200)->default('/var/lib/virthub/provision');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('os_template_provisioner', function (Blueprint $table) {
            $table->foreignId('os_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provisioner_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['os_template_id', 'provisioner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_template_provisioner');
        Schema::dropIfExists('provisioners');
    }
};
