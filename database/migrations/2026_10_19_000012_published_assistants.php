<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_assistants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assistant_id')->unique()->constrained()->cascadeOnDelete();
            $t->uuid('slug')->unique();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->text('instructions');
            $t->boolean('blocked')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_assistants');
    }
};
