<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HostedCodeAgents
{
    public function enabled()
    {
        return DB::table('hosted_code_agents')->where('enabled', true)
            ->where('api_ready', true)->where('api_protocol', 'chat')
            ->orderByRaw("CASE slug WHEN 'openmindai-super' THEN 1 WHEN 'openmindai-ultra' THEN 2 WHEN 'openmindai-lite' THEN 3 ELSE 4 END")
            ->orderBy('name')->get();
    }

    public function all()
    {
        return DB::table('hosted_code_agents')->orderBy('category')->orderBy('name')->get();
    }

    public function codeAgents()
    {
        return DB::table('hosted_code_agents')->whereIn('slug',
            ['openmindai-super', 'openmindai-ultra', 'openmindai-lite'])
            ->orderBy('id')->get();
    }

    public function find(string $slug)
    {
        return DB::table('hosted_code_agents')->where('slug', $slug)
            ->where('enabled', true)->where('api_ready', true)->first();
    }

    public function publicModels(): array
    {
        return $this->enabled()->map(fn ($model) => [
            'id' => $model->slug,
            'object' => 'model',
            'created' => $model->created_at ? strtotime($model->created_at) : 0,
            'owned_by' => 'openmindai',
            'name' => $model->name,
            'type' => $model->category,
        ])->all();
    }

    public function publicCatalog(): array
    {
        // Not-ready models are retained in the catalog so the frontend can explain
        // unavailable capabilities without pretending an API operation is usable.
        return DB::table('hosted_code_agents')->where('enabled', true)
            ->orderBy('category')->orderBy('name')->get()
            ->map(fn ($model) => [
                'id' => $model->slug,
                'object' => 'model',
                'name' => $model->name,
                'category' => $model->category,
                'description' => $model->summary,
                'free_endpoint' => (bool) $model->free_endpoint,
                'available' => (bool) $model->api_ready,
                'operation' => $model->api_protocol === 'chat' ? 'chat.completions' : 'invoke',
                'path' => $model->api_ready
                    ? ($model->api_protocol === 'chat' ? '/chat/completions' : '/models/'.$model->slug.'/invoke')
                    : null,
            ])->all();
    }
}
