<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Services\AdminSettings;
use App\Services\TwoFactor;
use App\Services\UnfamiliarLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController
{
    public function register(Request $r, UnfamiliarLogin $login)
    {
        $settings = app(AdminSettings::class);
        abort_unless($settings->enabled('registration_enabled'), 403, 'Registration is currently closed.');
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:254|unique:users',
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);
        $u = User::create($data);

        if ($settings->enabled('registration_email_verification_enabled')) {
            try {
                $u->notify(new VerifyEmail);
            } catch (\Throwable $exception) {
                // Never leave an inaccessible new account if the first verification email cannot be sent.
                $u->delete();
                abort(503, 'Verification email could not be sent. Please try again later.');
            }

            return response()->json([
                'verification_required' => true,
                'message' => 'Check your inbox for a verification link, then sign in.',
            ], 201);
        }

        $u->forceFill(['email_verified_at' => now()])->save();
        $token = $u->createToken('Openmindai Web', ['user'], now()->addDays(30))->plainTextToken;
        if ($settings->enabled('unusual_ip_verification_enabled')) {
            $login->trust($u, (string) $r->ip());
        }

        return response()->json([
            'user' => $u,
            'token' => $token,
            'token_type' => 'Bearer',
            'message' => 'Account created successfully.',
        ], 201);
    }

    public function login(Request $r, TwoFactor $two, UnfamiliarLogin $login)
    {
        $d = $r->validate([
            'email' => 'required|email', 'password' => 'required|string|max:1024',
            'code' => 'nullable|string|max:64', 'device_name' => 'required|string|max:80',
        ]);
        $u = User::where('email', $d['email'])->first();
        abort_unless($u && Hash::check($d['password'], $u->password), 422, 'Invalid credentials.');
        abort_unless($u->is_active, 403, 'Account disabled.');
        abort_unless($u->email_verified_at, 403, 'Verify your email first.');

        if ($u->two_factor_confirmed_at) {
            abort_unless($two->verify($u, $d['code'] ?? ''), 422, 'A valid two-factor or recovery code is required.');
        }
        abort_if($u->tokens()->count() >= 10, 422, 'Revoke an existing device first.');

        $challenge = $login->challenge($u, $r, $d['device_name']);
        if ($challenge) {
            return response()->json($challenge, 202);
        }

        $token = $u->createToken($d['device_name'], ['user'], now()->addDays(30))->plainTextToken;
        if (app(AdminSettings::class)->enabled('unusual_ip_verification_enabled')) {
            $login->trust($u, (string) $r->ip());
        }

        return ['token' => $token, 'token_type' => 'Bearer', 'user' => $u];
    }

    public function verifyLoginIp(Request $request, UnfamiliarLogin $login)
    {
        return $login->verify($request);
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return ['message' => 'If the account exists, a reset link has been sent.'];
    }

    public function reset(Request $r)
    {
        $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(6)]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $password) {
            DB::transaction(function () use ($u, $password) {
                $u->forceFill(['password' => $password, 'pending_email' => null, 'email_change_token' => null, 'email_change_expires_at' => null, 'remember_token' => bin2hex(random_bytes(30))])->save();
                $u->tokens()->delete();
                DB::table('sessions')->where('user_id', $u->id)->delete();
            });
        });
        abort_unless($status === Password::PASSWORD_RESET, 422, 'Invalid or expired reset token.');

        return ['message' => 'Password reset. All devices signed out.'];
    }

    public function verify(Request $r, int $id, string $hash)
    {
        $u = User::findOrFail($id);
        abort_unless(hash_equals(sha1($u->email), $hash), 403);
        $u->forceFill(['email_verified_at' => now()])->save();

        return response('Email verified. You may now log in.');
    }

    public function resend(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        $u = User::where('email', $r->email)->first();
        if ($u && ! $u->email_verified_at) {
            $u->notify(new VerifyEmail);
        }

        return ['message' => 'If eligible, a verification link has been sent.'];
    }
}
