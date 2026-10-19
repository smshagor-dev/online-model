<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationShare;
use App\Models\Memory;
use App\Models\User;
use App\Notifications\ChangeEmail;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController
{
    public function avatar(Request $r)
    {
        $d = $r->validate(['image' => 'required|string|max:128000']);
        $raw = base64_decode($d['image'], true);
        abort_unless($raw && strlen($raw) <= 96000, 422, 'Image must be at most 96 KB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        $size = @getimagesizefromstring($raw);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) && $size && $size[0] <= 512 && $size[1] <= 512, 422, 'Choose a PNG, JPEG or WebP up to 512×512 pixels.');
        $r->user()->forceFill(['avatar' => 'data:'.$mime.';base64,'.base64_encode($raw)])->save();

        return $r->user();
    }

    public function removeAvatar(Request $r)
    {
        $r->user()->forceFill(['avatar' => null])->save();

        return $r->user();
    }

    private function confirmIdentity(Request $r, TwoFactor $two): void
    {
        $r->validate(['password' => 'required|current_password', 'code' => 'nullable|string|max:64']);
        if ($r->user()->two_factor_confirmed_at) {
            abort_unless($two->verify($r->user(), $r->input('code', '')), 422, 'A valid two-factor or recovery code is required.');
        }
    }

    public function email(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')]]);
        $this->confirmIdentity($r, $two);
        abort_unless(filter_var(config('app.frontend_url'), FILTER_VALIDATE_URL), 503, 'The frontend URL is not configured.');
        $token = Str::random(64);
        $r->user()->forceFill(['pending_email' => $d['email'], 'email_change_token' => hash('sha256', $token), 'email_change_expires_at' => now()->addHour()])->save();
        $url = rtrim(config('app.frontend_url'), '/').'/confirm-email?account='.$r->user()->id.'&token='.$token;
        Notification::route('mail', $d['email'])->notify(new ChangeEmail($url));

        return ['message' => 'Check the new email address to confirm this change. Your current email remains active.', 'pending_email' => $d['email']];
    }

    public function cancelEmail(Request $r)
    {
        $r->user()->forceFill(['pending_email' => null, 'email_change_token' => null, 'email_change_expires_at' => null])->save();

        return response()->noContent();
    }

    public function confirmEmail(Request $r)
    {
        $d = $r->validate(['account' => 'required|integer', 'token' => 'required|string|size:64']);
        DB::transaction(function () use ($d) {
            $u = User::lockForUpdate()->find($d['account']);
            abort_unless($u && $u->is_active && $u->pending_email && $u->email_change_token && $u->email_change_expires_at?->isFuture() && hash_equals($u->email_change_token, hash('sha256', $d['token'])), 422, 'Invalid or expired email-change link.');
            abort_if(User::where('email', $u->pending_email)->exists(), 422, 'This address is no longer available. Cancel the change and try another address.');
            $old = $u->email;
            $u->forceFill(['email' => $u->pending_email, 'email_verified_at' => now(), 'pending_email' => null, 'email_change_token' => null, 'email_change_expires_at' => null])->save();
            $u->tokens()->delete();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            DB::table('password_reset_tokens')->whereIn('email', [$old, $u->email])->delete();
        });

        return ['message' => 'Email changed. Sign in with your new email address. All devices have been signed out.'];
    }

    public function bulk(Request $r, TwoFactor $two)
    {
        $d = $r->validate(['action' => 'required|in:archive_chats,delete_chats,clear_memories,revoke_shares', 'confirmation' => 'required|string']);
        abort_unless($d['confirmation'] === ['archive_chats' => 'ARCHIVE ALL CHATS', 'delete_chats' => 'DELETE ALL CHATS', 'clear_memories' => 'CLEAR ALL MEMORIES', 'revoke_shares' => 'REVOKE ALL LINKS'][$d['action']], 422, 'Confirmation does not match the action.');
        $this->confirmIdentity($r, $two);
        DB::transaction(function () use ($r, $d) {
            $id = $r->user()->id;
            match ($d['action']) {
                'archive_chats' => Conversation::where('user_id', $id)->whereNull('archived_at')->update(['archived_at' => now(), 'updated_at' => now()]),
                'delete_chats' => Conversation::where('user_id', $id)->delete(),
                'clear_memories' => Memory::where('user_id', $id)->delete(),
                'revoke_shares' => ConversationShare::where('user_id', $id)->delete(),
            };
        });

        return ['message' => 'Your data control action completed.'];
    }
}
