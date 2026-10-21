<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiCreditUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiCreditsTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::create(['name' => 'Credit user', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    private function admin(): User
    {
        $admin = $this->member();
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($admin)->withSession(['admin_verified' => true]);

        return $admin;
    }

    public function test_usage_is_idempotent_and_unknown_provider_balance_is_not_invented(): void
    {
        $usage = app(ApiCreditUsage::class);
        $user = $this->member();
        $id = $usage->connection('nvidia', 'nvidia/nemotron-3.5-lightning-30b-a3b', 'chat');
        $this->assertSame($id, $usage->connection('nvidia', 'nvidia/nemotron-3.5-lightning-30b-a3b', 'chat'));
        $usage->completed($user->id, $id, 'chat:call-one');
        $usage->completed($user->id, $id, 'chat:call-one');
        $this->assertDatabaseCount('api_credit_usage', 1);
        $this->assertSame(1, $usage->models()[0]['used']);
        $this->assertNull($usage->models()[0]['remaining']);
        DB::table('api_model_connections')->where('id', $id)->update(['monthly_credit_limit' => 7]);
        $this->assertSame(6, $usage->models()[0]['remaining']);
        $user->forceFill(['monthly_credit_limit' => 10])->save();
        $this->assertSame(9, collect($usage->users())->firstWhere('id', $user->id)['remaining']);
    }

    public function test_backend_tracks_single_completed_frontend_chat_not_failures(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        $headers = ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
        $request = [
            'message' => 'Hello', 'provider_model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
        ];
        $begin = $this->postJson('/api/v1/chat/frontend/begin', $request, $headers)->assertOk();
        $this->assertDatabaseCount('api_model_connections', 1);
        $this->assertDatabaseCount('api_credit_usage', 0);
        $body = [
            'ticket' => $begin->json('ticket'), 'content' => 'Done', 'status' => 'complete',
            'provider_model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
        ];
        $this->postJson('/api/v1/chat/frontend/finish', $body, $headers)->assertOk();
        $this->postJson('/api/v1/chat/frontend/finish', $body, $headers)->assertOk();
        $this->assertDatabaseCount('api_credit_usage', 1);
        $this->assertDatabaseHas('api_credit_usage', ['user_id' => $user->id, 'credits' => 1]);
    }

    public function test_regular_users_cannot_read_or_change_credit_reports(): void
    {
        $member = $this->member();
        $connection = app(ApiCreditUsage::class)->connection('nvidia', 'nvidia/example', 'chat');
        $this->actingAs($member)->withSession(['admin_verified' => true]);
        $this->get('/admin/api-credits')->assertForbidden();
        $this->patch('/admin/api-credits/models/'.$connection, [
            'monthly_credit_limit' => 25, 'current_password' => 'StrongPassword123',
        ])->assertForbidden();
        $this->assertNull(DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit'));
    }

    public function test_admin_can_view_charts_and_update_model_and_user_allocations(): void
    {
        $member = $this->member();
        $connection = app(ApiCreditUsage::class)->connection('nvidia', 'nvidia/example', 'chat');
        $this->admin();
        $this->get('/admin')->assertOk()->assertSee('API credits');
        $this->get('/admin/api-credits')->assertOk()->assertSee('nvidia/example')
            ->assertSee('not NVIDIA billing credits');
        $this->patch('/admin/api-credits/models/'.$connection, [
            'monthly_credit_limit' => 25, 'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');
        $this->patch('/admin/api-credits/models/'.$connection, [
            'monthly_credit_limit' => 25, 'current_password' => 'StrongPassword123',
        ])->assertRedirect();
        $this->assertSame(25, (int) DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit'));
        $this->patch('/admin/api-credits/users/'.$member->id, [
            'monthly_credit_limit' => 6, 'current_password' => 'StrongPassword123',
        ])->assertRedirect();
        $this->assertSame(6, (int) $member->fresh()->monthly_credit_limit);
    }

    public function test_previous_month_usage_is_not_counted_in_current_allocations(): void
    {
        $service = app(ApiCreditUsage::class);
        $member = $this->member();
        $connection = $service->connection('nvidia', 'nvidia/example', 'chat');
        $service->completed($member->id, $connection, 'chat:old');
        DB::table('api_credit_usage')->update(['created_at' => now()->subMonths(2)]);
        $this->assertSame(0, $service->models()[0]['used']);
    }
}
