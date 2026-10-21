<?php

namespace App\Http\Controllers;

use App\Services\HostedCodeAgents;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CodeAgentsAdminController
{
    public function index(HostedCodeAgents $agents)
    {
        return view('admin.code-agents', [
            'agents' => $agents->codeAgents(),
            'apps' => DB::table('first_party_apps')->orderByDesc('created_at')->get(),
            'apiBase' => rtrim(config('app.url'), '/').'/api/v1',
            'nvidiaReady' => strlen((string) config('models.nvidia_key')) >= 12,
        ]);
    }

    public function catalog(Request $request, HostedCodeAgents $agents)
    {
        $models = $agents->all();
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $categories = $models->pluck('category')->unique()->sort()->values()->all();
        $filtered = $models->filter(fn ($model) =>
            ($category === '' || $model->category === $category) &&
            ($q === '' || str_contains(mb_strtolower(
                $model->name.' '.$model->slug.' '.$model->provider_model),
                mb_strtolower($q))));
        return view('admin.nvidia-models', [
            'grouped' => $filtered->groupBy('category'),
            'categories' => $categories,
            'selectedCategory' => $category,
            'search' => $q,
            'total' => $models->count(),
            'configured' => $models->where('api_ready', true)->count(),
            'enabled' => $models->count(),
            'apiBase' => rtrim(config('app.url'), '/').'/api/v1/openmindai',
            'nvidiaReady' => strlen((string) config('models.nvidia_key')) >= 12,
        ]);
    }

    public function model(Request $request, string $slug)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|accepted',
            'api_ready' => 'sometimes|boolean',
            'include_model' => 'sometimes|boolean',
            'api_protocol' => 'sometimes|in:chat,json',
            'name' => 'sometimes|required|string|min:2|max:120',
            'provider_model' => ['sometimes', 'required', 'string', 'max:190',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9._\\/-]*$/',
                Rule::unique('hosted_code_agents', 'provider_model')->ignore($slug, 'slug')],
            'api_path' => ['sometimes', 'nullable', 'string', 'max:190',
                'regex:~^/v1/[a-zA-Z0-9][a-zA-Z0-9/_-]*$~'],
        ]);
        $model = DB::table('hosted_code_agents')->where('slug', $slug)->first();
        abort_unless($model, 404);
        if (array_key_exists('api_ready', $data) && $data['api_ready']) {
            $path = $data['api_path'] ?? $model->api_path;
            abort_unless($path && preg_match('~^/v1/[a-zA-Z0-9][a-zA-Z0-9/_-]*$~D', $path),
                422, 'Configure a verified NVIDIA /v1/ API path before enabling inference.');
            if (($data['api_protocol'] ?? $model->api_protocol) === 'chat') {
                abort_unless($path === '/v1/chat/completions', 422, 'Chat models must use the chat completions endpoint.');
            }
        }
        // Catalog entries must remain visible. A valid API key does not grant
        // upstream NVIDIA entitlements, so non-chat integrations still require
        // an explicitly reviewed protocol and path.
        unset($data['enabled']);
        if (($data['api_protocol'] ?? $model->api_protocol) === 'chat') {
            $data['api_path'] = '/v1/chat/completions';
            $data['api_ready'] = true;
        }
        DB::table('hosted_code_agents')->where('slug', $slug)->update([...$data, 'enabled' => true, 'updated_at' => now()]);
        return back()->with('status', 'NVIDIA model configuration saved.');
    }

    private function verify(Request $request, TwoFactor $two): void
    {
        $request->validate(['current_password' => 'required|current_password', 'code' => 'nullable|string|max:64']);
        if ($request->user()->two_factor_confirmed_at) {
            abort_unless($two->verify($request->user(), (string) $request->input('code', '')), 422, 'Valid two-factor code required.');
        }
    }

    public function addApp(Request $request, TwoFactor $two)
    {
        $this->verify($request, $two);
        $data = $request->validate(['name' => 'required|string|min:3|max:120']);
        abort_if(DB::table('first_party_apps')->where('enabled', true)->count() >= 20, 422, 'Too many registered apps.');
        $appId = 'openmindai-'.bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        DB::table('first_party_apps')->insert([
            'app_id' => $appId,
            'name' => trim($data['name']),
            'owner_user_id' => $request->user()->id,
            'encrypted_secret' => Crypt::encryptString($secret),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return back()->with('status', 'First-party app registered. Keep the signing secret in a trusted server environment.')
            ->with('first_party_id', $appId)->with('first_party_secret', $secret);
    }

    public function revealAppSecret(Request $request, int $app, TwoFactor $two)
    {
        // Viewing stored signing material requires reauthentication on every
        // request. Never send encrypted_secret to the Blade page or its data.
        $this->verify($request, $two);
        $row = DB::table('first_party_apps')->where('id', $app)->where('enabled', true)->first();
        abort_unless($row, 404, 'Active application not found.');

        return response()->json([
            'app_id' => $row->app_id,
            'signing_secret' => Crypt::decryptString($row->encrypted_secret),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function deleteApp(Request $request, int $app, TwoFactor $two)
    {
        $this->verify($request, $two);
        abort_unless(DB::table('first_party_apps')->where('id', $app)->delete() === 1, 404);

        return back()->with('status', 'Registered application permanently deleted. Its signing credentials can no longer be used.');
    }

    public function disableApp(Request $request, int $app, TwoFactor $two)
    {
        $this->verify($request, $two);
        abort_unless(DB::table('first_party_apps')->where('id', $app)->update(['enabled' => false, 'updated_at' => now()]), 404);
        return back()->with('status', 'First-party app access revoked.');
    }
}
