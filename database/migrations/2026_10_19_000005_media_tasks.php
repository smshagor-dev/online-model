<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 32);
            $t->text('prompt');
            $t->string('status', 16)->default('queued');
            $t->string('input_path')->nullable();
            $t->string('input_mime', 64)->nullable();
            $t->string('output_path')->nullable();
            $t->string('output_mime', 64)->nullable();
            $t->mediumText('result_text')->nullable();
            $t->text('error')->nullable();
            $t->unsignedBigInteger('bytes')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_tasks');
    }
};
