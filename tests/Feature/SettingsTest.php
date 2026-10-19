<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Memory;
use App\Models\User;
use App\Notifications\ChangeEmail;
use App\Services\ChatContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Settings User', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    public function test_preferences_persist_and_are_scoped_and_validated(): void
    {
        $u = $this->user();
        $other = $this->user();
        Sanctum::actingAs($u);
        $this->patchJson('/api/v1/preferences', ['nickname' => 'Shagor', 'occupation' => 'Developer', 'about_you' => 'Building AI', 'response_style' => 'professional', 'response_language' => 'Bangla', 'response_length' => 'short', 'theme' => 'system', 'accent_color' => 'purple', 'text_size' => 'large', 'send_on_enter' => false, 'reduced_motion' => true, 'completion_notifications' => true, 'is_admin' => true])->assertOk()->assertJsonPath('nickname', 'Shagor')->assertJsonPath('send_on_enter', false);
        $this->getJson('/api/v1/preferences')->assertJsonPath('response_language', 'Bangla')->assertJsonPath('reduced_motion', true);
        $this->assertFalse($u->fresh()->is_admin);
        $this->assertNull($other->fresh()->nickname);
        $this->patchJson('/api/v1/preferences', ['theme' => 'invalid', 'response_style' => 'unknown'])->assertUnprocessable();
    }

    public function test_personalization_is_used_by_context_and_can_be_disabled(): void
    {
        $u = $this->user();
        $u->forceFill(['nickname' => 'PRIVATE NICKNAME', 'occupation' => 'ENGINEER', 'custom_instructions' => 'PRIVATE RULE', 'response_language' => 'Bangla', 'response_length' => 'short'])->save();
        $ctx = app(ChatContext::class);
        $system = $ctx->build($u, null, 'Hello')[0]['content'];
        $this->assertStringContainsString('PRIVATE NICKNAME', $system);
        $this->assertStringContainsString('Bangla', $system);
        $u->forceFill(['personalization_enabled' => false])->save();
        $this->assertStringNotContainsString('PRIVATE RULE', $ctx->build($u, null, 'Hello')[0]['content']);
        $u->forceFill(['personalization_enabled' => true])->save();
        $this->assertStringNotContainsString('PRIVATE NICKNAME', $ctx->build($u, null, 'Hello', [], true)[0]['content']);
    }

    public function test_avatar_upload_remove_and_svg_rejection(): void
    {
        Sanctum::actingAs($this->user());
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=';
        $this->postJson('/api/v1/me/avatar', ['image' => $png])->assertOk()->assertJsonPath('avatar', 'data:image/png;base64,'.$png);
        $this->postJson('/api/v1/me/avatar', ['image' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>')])->assertUnprocessable();
        $this->deleteJson('/api/v1/me/avatar')->assertOk()->assertJsonPath('avatar', null);
    }

    public function test_email_change_keeps_existing_address_until_single_use_confirmation(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.example.com']);
        $u = $this->user();
        $old = $u->email;
        $u->createToken('fixture');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'StrongPassword123'])->assertOk()->assertJsonPath('pending_email', 'new@example.com');
        $this->assertSame($old, $u->fresh()->email);
        $url = '';
        Notification::assertSentOnDemand(ChangeEmail::class, function ($n, $channels, $notifiable) use (&$url) {
            $url = $n->url;

            return $notifiable->routes['mail'] === 'new@example.com';
        });
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->getJson('/api/v1/me')->assertJsonMissingPath('email_change_token')->assertJsonMissingPath('email_change_expires_at');
        $this->postJson('/api/v1/email/change/confirm', $query)->assertOk();
        $this->assertSame('new@example.com', $u->fresh()->email);
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->assertSame(0, $u->tokens()->count());
        $this->postJson('/api/v1/email/change/confirm', $query)->assertUnprocessable();
    }

    public function test_email_change_requires_password_and_rejects_existing_address(): void
    {
        Notification::fake();
        $u = $this->user();
        $other = $this->user();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/me/email', ['email' => $other->email, 'password' => 'StrongPassword123'])->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_email_confirmation_rejects_expiry_cancellation_and_disabled_account(): void
    {
        $u = $this->user();
        $token = str_repeat('a', 64);
        $u->forceFill(['pending_email' => 'new@example.com', 'email_change_token' => hash('sha256', $token), 'email_change_expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/email/change/confirm', ['account' => $u->id, 'token' => $token])->assertUnprocessable();
        $u->forceFill(['email_change_expires_at' => now()->addHour(), 'is_active' => false])->save();
        $this->postJson('/api/v1/email/change/confirm', ['account' => $u->id, 'token' => $token])->assertUnprocessable();
        $u->forceFill(['is_active' => true])->save();
        Sanctum::actingAs($u);
        $this->deleteJson('/api/v1/me/email')->assertNoContent();
        $this->postJson('/api/v1/email/change/confirm', ['account' => $u->id, 'token' => $token])->assertUnprocessable();
    }

    public function test_password_change_invalidates_pending_email_confirmation(): void
    {
        $u = $this->user();
        $u->forceFill(['pending_email' => 'new@example.com', 'email_change_token' => str_repeat('a', 64)])->save();
        Sanctum::actingAs($u);
        $this->putJson('/api/v1/me/password', ['current_password' => 'StrongPassword123', 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234'])->assertOk();
        $this->assertNull($u->fresh()->pending_email);
        $this->assertNull($u->fresh()->email_change_token);
    }

    public function test_bulk_archive_delete_and_memory_clear_only_affect_own_data(): void
    {
        $u = $this->user();
        $other = $this->user();
        Sanctum::actingAs($u);
        $a = Conversation::create(['user_id' => $u->id, 'title' => 'Own']);
        $a->messages()->create(['role' => 'user', 'content' => 'Own question']);
        $b = Conversation::create(['user_id' => $other->id, 'title' => 'Other']);
        Memory::create(['user_id' => $u->id, 'content' => 'Own']);
        Memory::create(['user_id' => $other->id, 'content' => 'Other']);
        $this->postJson('/api/v1/settings/data', ['action' => 'archive_chats', 'confirmation' => 'ARCHIVE ALL CHATS', 'password' => 'StrongPassword123'])->assertOk();
        $this->assertNotNull($a->fresh()->archived_at);
        $this->assertNull($b->fresh()->archived_at);
        $this->postJson('/api/v1/settings/data', ['action' => 'delete_chats', 'confirmation' => 'DELETE ALL CHATS', 'password' => 'StrongPassword123'])->assertOk();
        $this->assertDatabaseMissing('messages', ['conversation_id' => $a->id]);
        $this->assertDatabaseHas('conversations', ['id' => $b->id]);
        $this->postJson('/api/v1/settings/data', ['action' => 'clear_memories', 'confirmation' => 'CLEAR ALL MEMORIES', 'password' => 'StrongPassword123'])->assertOk();
        $this->assertDatabaseCount('memories', 1);
        $this->assertDatabaseHas('memories', ['user_id' => $other->id]);
    }

    public function test_bulk_actions_require_matching_confirmation_password_and_two_factor(): void
    {
        $u = $this->user();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/settings/data', ['action' => 'delete_chats', 'confirmation' => 'wrong', 'password' => 'StrongPassword123'])->assertUnprocessable();
        $this->postJson('/api/v1/settings/data', ['action' => 'delete_chats', 'confirmation' => 'DELETE ALL CHATS', 'password' => 'wrong'])->assertUnprocessable();
        $u->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->postJson('/api/v1/settings/data', ['action' => 'delete_chats', 'confirmation' => 'DELETE ALL CHATS', 'password' => 'StrongPassword123'])->assertUnprocessable();
        $this->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'StrongPassword123'])->assertUnprocessable();
    }

    public function test_export_includes_preferences_and_excludes_pending_email_token(): void
    {
        $u = $this->user();
        $u->forceFill(['nickname' => 'Shagor', 'email_change_token' => 'HIDDEN CHANGE SECRET'])->save();
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/export')->assertOk()->assertJsonPath('preferences.nickname', 'Shagor')->assertDontSee('HIDDEN CHANGE SECRET');
    }
}
