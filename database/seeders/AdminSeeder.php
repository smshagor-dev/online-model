<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AvatarImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $settings = config('admin.seed');
        $data = Validator::make(['name' => $settings['name'], 'email' => strtolower(trim((string) $settings['email'])), 'password' => $settings['password']], ['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'password' => ['required', 'string', 'max:1024', Password::min(6)]])->validate();
        $avatar = null;
        if ($path = $settings['avatar_path'] ?? null) {
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 96000) {
                throw new RuntimeException('ADMIN_AVATAR_PATH must be a readable local image of at most 96 KB.');
            }
            $avatar = app(AvatarImage::class)->fromBytes(file_get_contents($path));
        }
        DB::transaction(function () use ($data, $avatar) {
            $existing = User::whereRaw('LOWER(email) = ?', [$data['email']])->lockForUpdate()->first();
            if ($existing) {
                if (! $existing->is_admin) {
                    throw new RuntimeException('This email belongs to a regular user. The seeder will not grant it administrator access.');
                }
                $this->command?->info('Administrator already exists; no account details were changed.');

                return;
            }
            $user = new User($data);
            $user->forceFill(['is_admin' => true, 'is_active' => true, 'email_verified_at' => now(), 'avatar' => $avatar])->save();
            $this->command?->info('Administrator created. Sign in at /admin/login.');
        });
    }
}
