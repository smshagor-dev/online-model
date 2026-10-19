<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('custom_instructions')->nullable();
            $t->boolean('memory_enabled')->default(false);
            $t->string('theme')->default('dark');
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('instructions')->nullable();
            $t->timestamps();
        });
        Schema::table('conversations', function (Blueprint $t) {
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('pinned')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->index(['user_id', 'archived_at', 'pinned']);
        });
        Schema::table('messages', function (Blueprint $t) {
            $t->string('status')->default('complete');
            $t->string('feedback')->nullable();
        });
        Schema::create('memories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('content');
            $t->timestamps();
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('mime');
            $t->unsignedInteger('bytes');
            $t->mediumText('content');
            $t->timestamps();
        });
        Schema::create('conversation_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->json('snapshot');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['conversation_shares', 'attachments', 'memories'] as $name) {
            Schema::dropIfExists($name);
        }Schema::table('messages', fn (Blueprint $t) => $t->dropColumn(['status', 'feedback']));
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('project_id');
            $t->dropIndex(['user_id', 'archived_at', 'pinned']);
            $t->dropColumn(['pinned', 'archived_at', 'parent_id']);
        });
        Schema::dropIfExists('projects');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['custom_instructions', 'memory_enabled', 'theme']));
    }
};
