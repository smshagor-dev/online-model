<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactor
{
    public function verify(User $user, string $code, bool $recovery = true): bool
    {
        return DB::transaction(function () use ($user, $code, $recovery) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $locked->two_factor_secret) {
                return false;
            }
            $step = (new Google2FA)->verifyKeyNewer($locked->two_factor_secret, $code, $locked->two_factor_last_step ?? 0, 1);
            if ($step !== false) {
                $locked->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }
            if ($recovery && $locked->two_factor_confirmed_at) {
                $codes = $locked->two_factor_recovery_codes ?? [];
                foreach ($codes as $i => $hash) {
                    if (Hash::check($code, $hash)) {
                        unset($codes[$i]);
                        $locked->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                        return true;
                    }
                }
            }

            return false;
        });
    }

    public function recoveryCodes(User $user): array
    {
        $codes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 8));
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($v) => Hash::make($v), $codes)])->save();

        return $codes;
    }
}
