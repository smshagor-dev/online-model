<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailOtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmailOtp
{
    public function send(User $user, string $purpose): void
    {
        $email = mb_strtolower($user->email);
        $existing = DB::table('email_otp_challenges')->where('email', $email)->where('purpose', $purpose)->first();
        if ($existing && now()->diffInSeconds($existing->updated_at, false) > -60) {
            throw ValidationException::withMessages(['email' => 'Wait 60 seconds before requesting another code.']);
        }
        $code = (string) random_int(100000, 999999);
        DB::table('email_otp_challenges')->updateOrInsert(
            ['email' => $email, 'purpose' => $purpose],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]
        );
        try {
            $user->notify(new EmailOtpCode($code, $purpose));
        } catch (\Throwable $exception) {
            DB::table('email_otp_challenges')->where('email', $email)->where('purpose', $purpose)->delete();
            throw $exception;
        }
    }

    public function consume(string $email, string $purpose, string $code, callable $action): bool
    {
        return DB::transaction(function () use ($email, $purpose, $code, $action) {
            $query = DB::table('email_otp_challenges')
                ->where('email', mb_strtolower($email))->where('purpose', $purpose);
            $challenge = (clone $query)->lockForUpdate()->first();
            if (! $challenge || now()->greaterThan($challenge->expires_at)) {
                return false;
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                if ($challenge->attempts >= 4) {
                    (clone $query)->delete();
                } else {
                    (clone $query)->update(['attempts' => $challenge->attempts + 1]);
                }
                return false;
            }
            $action();
            (clone $query)->delete();
            return true;
        });
    }
}
