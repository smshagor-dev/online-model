<?php

namespace App\Http\Controllers;

use App\Services\HostedCodeAgents;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CodeAgentsAdminController
{
    public function index(HostedCodeAgents $agents)
    {
        return view('admin.code-agents', [
            'agents' => $agents->all(),
            'apps' => DB::table('first_party_apps')->orderByDesc('created_at')->get(),
            'apiBase' => rtrim(config('app.url'), '/').'/api/v1',
            'nvidiaReady' => strlen((string) config('models.nvidia_key')) >= 12,
        ]);
    }

    public function model(Request $request, string $slug)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        abort_unless(DB::table('hosted_code_agents')->where('slug', $slug)->exists(), 404);
        DB::table('hosted_code_agents')->where('slug', $slug)->update([
            'enabled' => $data['enabled'], 'updated_at' => now(),
        ]);
        return back()->with('status', 'Code model availability updated.');
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
        return back()->with('status', 'First-party app registered. Save its signing secret now.')
            ->with('first_party_id', $appId)->with('first_party_secret', $secret);
    }

    public function disableApp(Request $request, int $app, TwoFactor $two)
    {
        $this->verify($request, $two);
        abort_unless(DB::table('first_party_apps')->where('id', $app)->update(['enabled' => false, 'updated_at' => now()]), 404);
        return back()->with('status', 'First-party app access revoked.');
    }
}
