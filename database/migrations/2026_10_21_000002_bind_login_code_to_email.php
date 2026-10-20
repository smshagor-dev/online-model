<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_ip_challenges', function (Blueprint $table) {
            $table->char('email_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('login_ip_challenges', function (Blueprint $table) {
            $table->dropColumn('email_fingerprint');
        });
    }
};
