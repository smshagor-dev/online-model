<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('host', 253);
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 10)->default('tls');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_address', 254);
            $table->string('from_name', 120);
            $table->string('reply_to', 254)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
