<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiCreditsController
{
    public function index(ApiCreditUsage $usage)
    {
        return view('admin.api-credits', [
            'creditModels' => $usage->models(),
            'creditUsers' => $usage->users(),
            'monthLabel' => now()->format('F Y'),
        ]);
    }

    public function modelLimit(Request $request, int $id, TwoFactor $twoFactor)
    {
        $data = $request->validate([
            'monthly_credit_limit' => 'nullable|integer|min:1|max:100000000',
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($twoFactor->verify($request->user(), $data['code'] ?? ''), 422, 'A valid two-factor code is required.');
        }
        $query = DB::table('api_model_connections')->where('id', $id);
        abort_unless((clone $query)->exists(), 404);
        $query->update(['monthly_credit_limit' => $data['monthly_credit_limit'] ?? null, 'updated_at' => now()]);

        return back()->with('status', 'Model credit allocation saved.');
    }

    public function userLimit(Request $request, User $user, TwoFactor $twoFactor)
    {
        $data = $request->validate([
            'monthly_credit_limit' => 'nullable|integer|min:1|max:100000000',
            'current_password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($twoFactor->verify($request->user(), $data['code'] ?? ''), 422, 'A valid two-factor code is required.');
        }
        $user->forceFill(['monthly_credit_limit' => $data['monthly_credit_limit'] ?? null])->save();

        return back()->with('status', 'User credit allocation saved.');
    }
}
