<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NvidiaModelCatalogAdminTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'nvidia-admin@example.com',
            'password' => 'TestPassword123',
        ]);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($user)->withSession(['admin_verified' => true]);

        return $user;
    }

    public function test_admin_sees_all_42_models_and_can_filter_categories(): void
    {
        $this->administrator();

        $this->get('/admin/nvidia-models')->assertOk()
            ->assertSee('Free endpoint model catalog')
            ->assertSee('nemotron-3.5-embed-1b')
            ->assertSee('magpie-tts-zeroshot')
            ->assertSee('OpenMindAI Super');
        $this->assertDatabaseCount('hosted_code_agents', 42);
        $this->assertDatabaseMissing('hosted_code_agents', ['enabled' => false]);
        $this->get('/admin/nvidia-models?category=Embeddings')->assertOk()
            ->assertSee('nemotron-3.5-embed-1b')
            ->assertDontSee('magpie-tts-zeroshot');
        $this->assertDatabaseCount('hosted_code_agents', 42);
    }

    public function test_admin_configures_api_and_models_remain_visible(): void
    {
        $this->administrator();
        $slug = 'nvidia-nemotron-3.5-embed-1b';
        DB::table('hosted_code_agents')->where('slug', $slug)
            ->update(['api_path' => null, 'api_ready' => false]);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'enabled' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('hosted_code_agents', ['slug' => $slug, 'enabled' => true]);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true,
        ])->assertStatus(422);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true,
            'api_protocol' => 'json',
            'api_path' => '/v1/embeddings',
            'include_model' => true,
            'name' => 'Nemotron Embed 1B',
            'provider_model' => 'nvidia/nemotron-3.5-embed-1b',
        ])->assertRedirect();

        $this->assertDatabaseHas('hosted_code_agents', [
            'slug' => $slug, 'enabled' => true, 'api_ready' => true,
            'api_path' => '/v1/embeddings', 'name' => 'Nemotron Embed 1B',
        ]);

        $this->patchJson('/admin/nvidia-models/'.$slug, [
            'api_ready' => true,
            'api_path' => '/v1/https://attacker.example/',
        ])->assertUnprocessable();
    }

    public function test_regular_users_cannot_access_nvidia_admin_controls(): void
    {
        $member = User::create([
            'name' => 'Member',
            'email' => 'nvidia-member@example.com',
            'password' => 'TestPassword123',
        ]);
        $this->actingAs($member)->withSession(['admin_verified' => true]);

        $this->get('/admin/nvidia-models')->assertForbidden();
        $this->patch('/admin/nvidia-models/nvidia-nemotron-3.5-embed-1b', [
            'enabled' => true,
        ])->assertForbidden();
        $this->assertSame(42, DB::table('hosted_code_agents')->where('enabled', true)->count());
    }
}
