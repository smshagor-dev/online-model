<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->text('instructions');
            $t->foreignId('model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $t->json('attachment_ids')->nullable();
            $t->timestamps();
        });
        Schema::table('conversations', fn (Blueprint $t) => $t->foreignId('assistant_id')->nullable()->constrained()->nullOnDelete());
        Schema::create('scheduled_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->text('prompt');
            $t->foreignId('model_id')->constrained('ai_models')->cascadeOnDelete();
            $t->string('kind', 16)->default('chat');
            $t->string('frequency', 16)->default('once');
            $t->timestamp('next_run_at')->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('task_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('scheduled_task_id')->constrained()->cascadeOnDelete();
            $t->string('status', 16)->default('queued');
            $t->json('steps')->nullable();
            $t->json('sources')->nullable();
            $t->mediumText('result')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('canvas_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('canvas_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('title');
            $t->string('format');
            $t->mediumText('content');
            $t->timestamps();
            $t->unique(['canvas_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_versions');
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('scheduled_tasks');
        Schema::table('conversations', fn (Blueprint $t) => $t->dropConstrainedForeignId('assistant_id'));
        Schema::dropIfExists('assistants');
    }
};
