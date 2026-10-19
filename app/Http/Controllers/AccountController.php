<?php

namespace App\Http\Controllers;

use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use PragmaRX\Google2FA\Google2FA;

class AccountController
{
    public function show(Request $r)
    {
        return $r->user();
    }

    public function update(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120']);
        $r->user()->update($d);

        return $r->user();
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()]]);
        $u = $r->user();
        $u->update(['password' => $d['password']]);
        $u->tokens()->delete();
        DB::table('sessions')->where('user_id', $u->id)->delete();

        return ['message' => 'Password changed. Sign in again.'];
    }

    public function tokens(Request $r)
    {
        return $r->user()->tokens()->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);
    }

    public function revoke(Request $r, int $id)
    {
        $r->user()->tokens()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function setup(Request $r)
    {
        $r->validate(['password' => 'required|current_password']);
        $u = $r->user();
        abort_if($u->two_factor_confirmed_at, 409, 'Two-factor authentication already enabled.');
        $g = new Google2FA;
        $secret = $g->generateSecretKey();
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_last_step' => null])->save();

        return ['secret' => $secret, 'otpauth_url' => $g->getQRCodeUrl(config('app.name'), $u->email, $secret)];
    }

    public function confirm(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['code' => 'required|string|max:16']);
        $u = $r->user();
        abort_if($u->two_factor_confirmed_at, 409);
        abort_unless($two->verify($u, $d['code'], false), 422, 'Invalid code.');
        $u->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = $two->recoveryCodes($u);
        $u->tokens()->where('id', '!=', $u->currentAccessToken()->id)->delete();
        DB::table('sessions')->where('user_id', $u->id)->delete();

        return ['recovery_codes' => $codes];
    }

    public function disable(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['password' => 'required|current_password', 'code' => 'required|string|max:64']);
        $u = $r->user();
        abort_unless($two->verify($u, $d['code']), 422, 'Invalid code.');
        $u->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $u->tokens()->where('id', '!=', $u->currentAccessToken()->id)->delete();
        DB::table('sessions')->where('user_id', $u->id)->delete();

        return response()->noContent();
    }

    public function recovery(Request $r, TwoFactor $two)
    {
        $r->validate(['password' => 'required|current_password', 'code' => 'required|string|max:64']);
        abort_unless($r->user()->two_factor_confirmed_at && $two->verify($r->user(), $r->input('code')), 422, 'Valid two-factor code required.');

        return ['recovery_codes' => $two->recoveryCodes($r->user())];
    }

    public function logoutAll(Request $r)
    {
        $r->user()->tokens()->delete();

        return response()->noContent();
    }

    public function delete(Request $r, TwoFactor $two)
    {
        $r->validate(['password' => 'required|current_password', 'code' => 'nullable|string|max:64']);
        $u = $r->user();
        if ($u->two_factor_confirmed_at) {
            abort_unless($two->verify($u, $r->input('code', '')), 422, 'Invalid code.');
        }abort_if($u->is_admin, 409, 'Admin accounts cannot be self-deleted.');
        DB::transaction(function () use ($u) {
            $u->tokens()->delete();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            $u->delete();
        });

        return response()->noContent();
    }
}
