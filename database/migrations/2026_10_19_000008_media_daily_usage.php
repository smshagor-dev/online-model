<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_daily_usage', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('kind', 8);
            $table->unsignedInteger('requests')->default(0);
            $table->primary(['user_id', 'day', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_daily_usage');
    }
};
