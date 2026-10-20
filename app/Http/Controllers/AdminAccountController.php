<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AvatarImage;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AdminAccountController
{
    public function profile(Request $r)
    {
        return view('admin.profile', ['account' => $r->user()]);
    }

    public function create()
    {
        return view('admin.create-administrator');
    }

    private function identity(Request $r, TwoFactor $two): void
    {
        $r->validate(['current_password' => 'required|string|max:1024|current_password:web', 'code' => 'nullable|string|max:64']);
        if ($r->user()->two_factor_confirmed_at) {
            abort_unless($two->verify($r->user(), $r->input('code', '')), 422, 'A valid two-factor or recovery code is required.');
        }
    }

    private function photo(Request $r): array
    {
        $r->validate(['avatar' => 'nullable|string|max:128000', 'avatar_upload' => 'nullable|file|max:94', 'remove_avatar' => 'sometimes|boolean']);
        if ($r->filled('avatar')) {
            $raw = base64_decode($r->input('avatar'), true);
            abort_unless($raw !== false, 422, 'Invalid photo encoding.');

            return ['avatar' => app(AvatarImage::class)->fromBytes($raw)];
        }
        if ($r->hasFile('avatar_upload')) {
            return ['avatar' => app(AvatarImage::class)->fromBytes($r->file('avatar_upload')->get())];
        }

        return $r->boolean('remove_avatar') ? ['avatar' => null] : [];
    }

    public function update(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120']);
        $r->user()->forceFill([...$d, ...$this->photo($r)])->save();

        return back()->with('status', 'Administrator profile updated.');
    }

    public function store(Request $r, TwoFactor $two)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        abort_if(User::whereRaw('LOWER(email) = ?', [$d['email']])->exists(), 422, 'This email already belongs to an account.');
        $photo = $this->photo($r);
        $this->identity($r, $two);
        $admin = new User($d);
        $admin->forceFill(['is_admin' => true, 'is_active' => true, 'email_verified_at' => now(), ...$photo])->save();

        return redirect('/admin/users/'.$admin->id)->with('status', 'New administrator created. They can sign in at /admin/login.');
    }

    public function password(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        $this->identity($r, $two);
        DB::transaction(function () use ($r, $d) {
            $r->user()->forceFill(['password' => $d['password'], 'remember_token' => bin2hex(random_bytes(30)), 'pending_email' => null, 'email_change_token' => null, 'email_change_expires_at' => null])->save();
            $r->user()->tokens()->delete();
            DB::table('sessions')->where('user_id', $r->user()->id)->delete();
            DB::table('password_reset_tokens')->where('email', $r->user()->email)->delete();
        });
        $r->session()->regenerate(true);

        return back()->with('status', 'Password changed. Other devices have been signed out.');
    }

    public function email(Request $r, SettingsController $settings, TwoFactor $two)
    {
        $result = $settings->email($r, $two);

        return back()->with('status', $result['message']);
    }

    public function cancelEmail(Request $r, SettingsController $settings)
    {
        $settings->cancelEmail($r);

        return back()->with('status', 'Pending email change cancelled.');
    }
}
