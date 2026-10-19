<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->mediumText('avatar')->nullable();
            $t->string('nickname', 80)->nullable();
            $t->string('occupation', 160)->nullable();
            $t->text('about_you')->nullable();
            $t->boolean('personalization_enabled')->default(true);
            $t->string('response_style', 24)->default('default');
            $t->string('response_length', 16)->default('default');
            $t->string('response_language', 24)->default('auto');
            $t->string('accent_color', 16)->default('default');
            $t->string('text_size', 16)->default('default');
            $t->boolean('reduced_motion')->default(false);
            $t->boolean('send_on_enter')->default(true);
            $t->boolean('completion_notifications')->default(false);
            $t->string('pending_email', 254)->nullable();
            $t->string('email_change_token', 64)->nullable();
            $t->timestamp('email_change_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['avatar', 'nickname', 'occupation', 'about_you', 'personalization_enabled', 'response_style', 'response_length', 'response_language', 'accent_color', 'text_size', 'reduced_motion', 'send_on_enter', 'completion_notifications', 'pending_email', 'email_change_token', 'email_change_expires_at']));
    }
};
