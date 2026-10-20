<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    private string $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQ0AAAAASUVORK5CYII=';

    private function admin(): User
    {
        $u = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'StrongPassword123']);
        $u->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($u)->withSession(['admin_verified' => true]);

        return $u;
    }

    private function creation(): array
    {
        return ['name' => 'Second admin', 'email' => 'second@example.com', 'password' => 'AnotherPassword123', 'password_confirmation' => 'AnotherPassword123', 'current_password' => 'StrongPassword123', 'avatar' => $this->png];
    }

    public function test_admin_seeder_is_explicit_idempotent_and_keeps_existing_account_details(): void
    {
        config(['admin.seed' => ['name' => 'Seed owner', 'email' => 'Seed@Example.com', 'password' => 'StrongPassword123', 'avatar_path' => null]]);
        $this->seed(DatabaseSeeder::class);
        $u = User::firstOrFail();
        $this->assertTrue($u->is_admin);
        $this->assertTrue($u->is_active);
        $this->assertNotNull($u->email_verified_at);
        $this->assertSame('seed@example.com', $u->email);
        $this->assertTrue(Hash::check('StrongPassword123', $u->password));
        $u->update(['name' => 'Edited owner']);
        config(['admin.seed.password' => 'DifferentPassword123']);
        $this->seed(AdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Edited owner', $u->fresh()->name);
        $this->assertTrue(Hash::check('StrongPassword123', $u->fresh()->password));
    }

    public function test_seeder_never_promotes_an_existing_regular_user(): void
    {
        $u = User::create(['name' => 'Regular', 'email' => 'User@Example.com', 'password' => 'StrongPassword123']);
        config(['admin.seed' => ['name' => 'Admin', 'email' => 'user@example.com', 'password' => 'StrongPassword123', 'avatar_path' => null]]);
        try {
            $this->seed(AdminSeeder::class);
            $this->fail('Seeder promoted a regular user.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('regular user', $e->getMessage());
        }
        $this->assertFalse($u->fresh()->is_admin);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_seed_password_is_required_and_optional_local_image_is_validated(): void
    {
        config(['admin.seed' => ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => '', 'avatar_path' => null]]);
        try {
            $this->seed(AdminSeeder::class);
            $this->fail('Missing password accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
        $path = tempnam(sys_get_temp_dir(), 'admin-photo-');
        try {
            file_put_contents($path, base64_decode($this->png));
            config(['admin.seed.password' => 'StrongPassword123', 'admin.seed.avatar_path' => $path]);
            $this->seed(AdminSeeder::class);
            $this->assertSame('data:image/png;base64,'.$this->png, User::first()->avatar);
        } finally {
            unlink($path);
        }
    }

    public function test_verified_admin_can_create_admin_with_photo_and_new_admin_can_sign_in(): void
    {
        $owner = $this->admin();
        $this->get('/admin/administrators/create')->assertOk()->assertSee('Create administrator');
        $this->post('/admin/administrators', $this->creation())->assertRedirect();
        $u = User::where('email', 'second@example.com')->firstOrFail();
        $this->assertTrue($u->is_admin);
        $this->assertTrue($u->is_active);
        $this->assertNotNull($u->email_verified_at);
        $this->assertTrue(Hash::check('AnotherPassword123', $u->password));
        $this->assertSame('data:image/png;base64,'.$this->png, $u->avatar);
        $this->assertTrue($owner->fresh()->is_admin);
        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $u->email, 'password' => 'AnotherPassword123'])->assertRedirect('/admin');
        $this->get('/admin/profile')->assertOk()->assertSee('Second admin')->assertSee('data:image/png;base64,'.$this->png, false);
    }

    public function test_admin_creation_requires_verified_session_current_password_and_two_factor(): void
    {
        $u = $this->admin();
        $this->withSession(['admin_verified' => false])->post('/admin/administrators', $this->creation())->assertForbidden();
        $this->withSession(['admin_verified' => true])->post('/admin/administrators', array_replace($this->creation(), ['current_password' => 'wrong']))->assertSessionHasErrors('current_password');
        $u->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->mock(TwoFactor::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn(false));
        $this->post('/admin/administrators', $this->creation())->assertStatus(422);
        $this->assertDatabaseCount('users', 1);
        $this->mock(TwoFactor::class, fn ($mock) => $mock->shouldReceive('verify')->once()->withArgs(fn ($account, $code) => $account->id === $u->id && $code === 'valid-code')->andReturn(true));
        $this->post('/admin/administrators', [...$this->creation(), 'code' => 'valid-code'])->assertRedirect();
        $this->assertDatabaseCount('users', 2);
        $u->forceFill(['is_admin' => false])->save();
        $this->post('/admin/administrators', $this->creation())->assertForbidden();
    }

    public function test_profile_photo_update_remove_and_raster_validation(): void
    {
        $u = $this->admin();
        $this->patch('/admin/profile', ['name' => 'Updated owner', 'avatar' => $this->png, 'is_admin' => false, 'email' => 'ignored@example.com'])->assertRedirect();
        $this->assertSame('Updated owner', $u->fresh()->name);
        $this->assertTrue($u->fresh()->is_admin);
        $this->assertSame('owner@example.com', $u->fresh()->email);
        $this->patch('/admin/profile', ['name' => 'Owner', 'avatar' => base64_encode('<svg onload="alert(1)"/>')])->assertSessionHasErrors('avatar');
        $this->assertSame('data:image/png;base64,'.$this->png, $u->fresh()->avatar);
        $this->patch('/admin/profile', ['name' => 'Owner', 'remove_avatar' => true])->assertRedirect();
        $this->assertNull($u->fresh()->avatar);
        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode($this->png));
        $this->patch('/admin/profile', ['name' => 'Owner', 'avatar_upload' => $file])->assertRedirect();
        $this->assertSame('data:image/png;base64,'.$this->png, $u->fresh()->avatar);
    }

    public function test_password_change_revokes_other_devices_and_keeps_current_admin_session(): void
    {
        $u = $this->admin();
        $u->createToken('Old API token');
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $this->post('/admin/profile/password', ['current_password' => 'StrongPassword123', 'password' => 'NewStrongPassword123', 'password_confirmation' => 'NewStrongPassword123'])->assertRedirect();
        $this->assertTrue(Hash::check('NewStrongPassword123', $u->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser']);
        $this->get('/admin/profile')->assertOk();
    }

    public function test_existing_email_cannot_be_recreated_and_email_changes_keep_verification_flow(): void
    {
        $u = $this->admin();
        Notification::fake();
        config(['app.frontend_url' => 'https://app.example.com']);
        $this->post('/admin/administrators', array_replace($this->creation(), ['email' => 'OWNER@EXAMPLE.COM']))->assertSessionHasErrors('email');
        $this->post('/admin/profile/email', ['email' => 'replacement@example.com', 'password' => 'StrongPassword123'])->assertRedirect();
        $this->assertSame('owner@example.com', $u->fresh()->email);
        $this->assertSame('replacement@example.com', $u->fresh()->pending_email);
        $this->delete('/admin/profile/email')->assertRedirect();
        $this->assertNull($u->fresh()->pending_email);
    }
}
