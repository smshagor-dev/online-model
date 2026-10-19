<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class HostedChatActivity
{
    public function recover(int $user): void
    {
        $activity = Cache::get('hosted-chat-user:'.$user);
        if ($activity && $activity['heartbeat'] < now()->subMinutes(10)->timestamp) {
            // Only the abandoned owner can be released; a newer local/hosted lock survives.
            Cache::restoreLock('chat-user:'.$user, $activity['owner'])->release();
        }
    }

    public function heartbeat(int $user, string $owner): void
    {
        Cache::put('hosted-chat-user:'.$user, ['owner' => $owner, 'heartbeat' => now()->timestamp], 172800);
    }

    public function finish(int $user, string $owner): void
    {
        if ((Cache::get('hosted-chat-user:'.$user)['owner'] ?? null) === $owner) {
            Cache::forget('hosted-chat-user:'.$user);
        }
    }
}
