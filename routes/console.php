<?php

use App\Models\ConversationShare;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('admin:create {email} {name}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists.');

        return 1;
    }
    $password = $this->secret('Password (12+ characters, uppercase, lowercase and number)');
    if (! is_string($password) || strlen($password) < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
        $this->error('Password does not meet policy.');

        return 1;
    }
    $u = User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => $password]);
    $u->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
    $this->info('Administrator created. Enable 2FA using the account API.');
})->purpose('Create an administrator without a default password');
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::call(fn () => Message::where('status', 'generating')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed']))->everyMinute();
Schedule::call(fn () => ConversationShare::where('expires_at', '<', now())->delete())->daily();
