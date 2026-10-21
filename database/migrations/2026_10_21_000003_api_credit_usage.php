<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_model_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('model_key', 190);
            $table->string('kind', 32);
            $table->unsignedInteger('monthly_credit_limit')->nullable();
            $table->timestamp('last_request_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'model_key', 'kind'], 'api_models_identity');
        });
        Schema::create('api_credit_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('api_model_connections')->cascadeOnDelete();
            $table->string('source_key', 145)->unique();
            $table->unsignedInteger('credits')->default(1);
            $table->timestamp('created_at');
            $table->index(['connection_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credit_usage');
        Schema::dropIfExists('api_model_connections');
    }
};
