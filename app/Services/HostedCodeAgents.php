<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class HostedCodeAgents
{
    public function enabled()
    {
        return DB::table('hosted_code_agents')->where('api_ready', true)->where('api_protocol', 'chat')
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
            ->where('api_ready', true)->first();
    }

    public function publicModels(): array
    {
        return $this->all()->map(fn ($model) => [
            'id' => $model->slug,
            'object' => 'model',
            'created' => $model->created_at ? strtotime($model->created_at) : 0,
            'owned_by' => 'openmindai',
            'name' => $model->name,
            'type' => $model->category,
            'active' => (bool) $model->enabled,
            'available' => (bool) $model->api_ready,
            'inference_status' => $model->api_ready ? 'configured' : 'setup_required',
            'provider_verified' => $model->last_test_status === 'success',
            'integration_requirements' => config('nvidia_specialists.models.'.$model->slug),
            'operation' => $model->slug === 'nvidia-cosmos-transfer2.5-2b'
                ? 'video.transfer'
                : (isset(config('nvidia_av.routes', [])[$model->slug])
                ? 'scene.process'
                : ($model->slug === 'nvidia-streampetr'
                ? 'scene.process'
                : (isset(config('maxine.models', [])[$model->slug])
                ? 'media.process'
                : ($model->slug === 'nvidia-cosmos3-nano' ? 'generate' : ($model->api_protocol === 'chat' ? 'chat.completions' : 'invoke'))))),
        ])->all();
    }

    public function publicCatalog(): array
    {
        // Catalog visibility is permanent and independent of the provider's
        // availability, account entitlement, and per-model API configuration.
        return $this->all()
            ->map(fn ($model) => [
                'id' => $model->slug,
                'object' => 'model',
                'name' => $model->name,
                'category' => $model->category,
                'description' => $model->summary,
                'free_endpoint' => (bool) $model->free_endpoint,
                'listed' => true,
                'provider_access' => $model->last_test_status === 'success' ? 'verified' : 'unverified',
                'active' => (bool) $model->enabled,
            'available' => (bool) $model->api_ready,
            'inference_status' => $model->api_ready ? 'configured' : 'setup_required',
                'provider_verified' => $model->last_test_status === 'success',
                'integration_requirements' => config('nvidia_specialists.models.'.$model->slug),
                'last_test_status' => $model->last_test_status,
                'last_tested_at' => $model->last_tested_at,
                'operation' => $model->slug === 'nvidia-cosmos-transfer2.5-2b'
                ? 'video.transfer'
                : (isset(config('nvidia_av.routes', [])[$model->slug])
                ? 'scene.process'
                : ($model->slug === 'nvidia-streampetr'
                ? 'scene.process'
                : (isset(config('maxine.models', [])[$model->slug])
                ? 'media.process'
                : ($model->slug === 'nvidia-cosmos3-nano' ? 'generate' : ($model->api_protocol === 'chat' ? 'chat.completions' : 'invoke'))))),
                'path' => $model->api_ready
                    ? ($model->slug === 'nvidia-cosmos-transfer2.5-2b'
                        ? '/transfer/generations'
                        : (isset(config('nvidia_av.routes', [])[$model->slug])
                        ? '/av-nim/'.$model->slug.'/jobs'
                        : ($model->slug === 'nvidia-streampetr'
                        ? '/av/streampetr/jobs'
                        : (isset(config('maxine.models', [])[$model->slug])
                        ? '/maxine/'.$model->slug.'/jobs'
                        : ($model->slug === 'nvidia-cosmos3-nano' ? '/cosmos/generations' : ($model->api_protocol === 'chat' ? '/chat/completions' : '/models/'.$model->slug.'/invoke'))))))
                    : null,
            ])->all();
    }
}
