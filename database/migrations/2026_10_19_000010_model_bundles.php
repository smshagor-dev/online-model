<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_bundles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('purpose', 20);
            $table->string('repository', 160);
            $table->string('revision', 40);
            $table->string('license', 160);
            $table->json('files');
            $table->unsignedBigInteger('bytes');
            $table->string('status', 20)->default('available');
            $table->text('download_error')->nullable();
            $table->timestamps();
            $table->unique(['repository', 'revision', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_bundles');
    }
};
