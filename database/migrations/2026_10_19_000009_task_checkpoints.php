<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->longText('checkpoint')->nullable();
            $table->uuid('execution_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', fn (Blueprint $table) => $table->dropColumn(['checkpoint', 'execution_token']));
    }
};
