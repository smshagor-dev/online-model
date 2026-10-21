<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TwoFactor;
use Laravel\Sanctum\PersonalAccessToken;

class DeveloperKeysController
{
    public function index(Request $request)
    {
        return [
            'base_url' => rtrim(config('app.url'), '/').'/api/v1/openmindai',
            'keys' => $request->user()->tokens()->where('name', 'like', 'developer:%')
                ->latest()->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at']),
        ];
    }

    public function store(Request $request, TwoFactor $two)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:64',
            'password' => 'required|current_password',
            'code' => 'nullable|string|max:64',
        ]);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($two->verify($request->user(), (string) ($data['code'] ?? '')), 422, 'A valid two-factor code is required.');
        }
        abort_if($request->user()->tokens()->where('name', 'like', 'developer:%')->count() >= 10, 422, 'Maximum 10 active API keys.');
        $name = 'developer:'.trim($data['name']);
        $token = $request->user()->createToken($name, ['openmindai:invoke'], now()->addDays(90));
        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $name,
            'api_key' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'base_url' => rtrim(config('app.url'), '/').'/api/v1/openmindai',
            'note' => 'Store this secret securely. It cannot be shown again.',
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->tokens()->where('name', 'like', 'developer:%')->findOrFail($id)->delete();
        return response()->noContent();
    }
}
