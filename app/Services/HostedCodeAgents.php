<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HostedCodeAgents
{
    public function enabled()
    {
        return DB::table('hosted_code_agents')->where('enabled', true)->orderByRaw("CASE slug WHEN 'openmindai-super' THEN 1 WHEN 'openmindai-ultra' THEN 2 ELSE 3 END")->get();
    }

    public function all()
    {
        return DB::table('hosted_code_agents')->orderBy('id')->get();
    }

    public function find(string $slug)
    {
        return DB::table('hosted_code_agents')->where('slug', $slug)->where('enabled', true)->first();
    }

    public function publicModels(): array
    {
        return $this->enabled()->map(fn ($agent) => [
            'id' => $agent->slug,
            'object' => 'model',
            'created' => $agent->created_at ? strtotime($agent->created_at) : 0,
            'owned_by' => 'openmindai',
            'name' => $agent->name,
            'type' => 'code',
        ])->all();
    }
}
