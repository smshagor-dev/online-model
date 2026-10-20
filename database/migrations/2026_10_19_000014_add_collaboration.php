<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->timestamps();
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 12)->default('member');
            $t->timestamps();
            $t->unique(['team_id', 'user_id']);
        });
        Schema::create('team_invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->string('email');
            $t->string('role', 12);
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['team_id', 'email']);
        });
        Schema::create('team_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->longText('content');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('team_threads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->timestamps();
        });
        Schema::create('team_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_thread_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('author', 120);
            $t->longText('content');
            $t->timestamps();
        });
        Schema::create('connector_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('server', 80);
            $t->string('endpoint_hash', 64);
            $t->text('credential')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'server']);
        });
        Schema::create('connector_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('server', 80);
            $t->string('tool', 120);
            $t->string('request_key', 36);
            $t->string('fingerprint', 64);
            $t->text('arguments');
            $t->longText('result')->nullable();
            $t->string('status', 20);
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        foreach (['connector_calls', 'connector_accounts', 'team_posts', 'team_threads', 'team_documents', 'team_invites', 'team_members', 'teams'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
