<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $twoFactor = false): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'StrongPassword123']);
        $user->forceFill(['is_admin' => true, 'email_verified_at' => now(), 'two_factor_confirmed_at' => $twoFactor ? now() : null, 'two_factor_secret' => $twoFactor ? 'JBSWY3DPEHPK3PXP' : null, 'two_factor_recovery_codes' => $twoFactor ? [Hash::make('recovery-example')] : []])->save();

        return $user;
    }

    public function test_disabled_two_factor_never_displays_or_requires_code(): void
    {
        $user = $this->account();
        $this->get('/admin/login')->assertOk()->assertDontSee('name="code"', false);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertRedirect('/admin')->assertSessionHas('admin_verified', true)->assertSessionMissing('admin_challenge');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_enabled_two_factor_only_challenges_after_valid_password(): void
    {
        $user = $this->account(true);
        $this->from('/admin/login')->post('/admin/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email')->assertSessionMissing('admin_challenge');
        $this->get('/admin/login')->assertDontSee('name="code"', false);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'StrongPassword123'])->assertRedirect('/admin/login')->assertSessionHas('admin_challenge');
        $this->assertGuest();
        $this->get('/admin/login')->assertSee('name="code"', false)->assertDontSee('name="password"', false);
        $this->from('/admin/login')->post('/admin/login', ['code' => 'invalid'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/admin/login', ['code' => 'recovery-example'])->assertRedirect('/admin')->assertSessionMissing('admin_challenge');
        $this->assertAuthenticatedAs($user);
        $this->assertSame([], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_expired_challenge_cannot_authenticate_and_can_restart(): void
    {
        $user = $this->account(true);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'StrongPassword123']);
        $this->travel(6)->minutes();
        $this->post('/admin/login', ['code' => 'recovery-example'])->assertRedirect('/admin/login')->assertSessionMissing('admin_challenge');
        $this->assertGuest();
        $this->travelBack();
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'StrongPassword123']);
        $this->get('/admin/login?restart=1')->assertSessionMissing('admin_challenge')->assertDontSee('name="code"', false);
    }
}
