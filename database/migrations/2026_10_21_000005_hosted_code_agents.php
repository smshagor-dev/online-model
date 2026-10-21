<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_code_agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('provider_model', 190)->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('hosted_code_agents')->insert([
            ['slug' => 'openmindai-super', 'name' => 'OpenMindAI Super', 'provider_model' => 'nvidia/nemotron-3.5-super-vl-120b-a12b', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'openmindai-ultra', 'name' => 'OpenMindAI Ultra', 'provider_model' => 'nvidia/nemotron-3-ultra-550b-a55b', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'openmindai-lite', 'name' => 'OpenMindAI Lite', 'provider_model' => 'nvidia/nemotron-3.5-lightning-30b-a3b', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('first_party_apps', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 64)->unique();
            $table->string('name', 120);
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('encrypted_secret');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_party_apps');
        Schema::dropIfExists('hosted_code_agents');
    }
};
