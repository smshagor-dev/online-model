<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatContext;
use App\Services\WebResearch;
use App\Services\WebSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

// Only the Next.js server can prepare/finalize a hosted inference turn.
// Laravel owns authentication, context, rate limits and message persistence.
class FrontendChatController
{
    private function guard(Request $r): void
    {
        $key = (string) config('models.frontend_chat_key');
        abort_unless(strlen($key) >= 32, 503, 'Frontend chat bridge is not configured.');
        abort_unless(hash_equals($key, (string) $r->header('X-Chat-Bridge-Key')), 403);
    }

    public function begin(Request $r, ChatContext $context)
    {
        $this->guard($r);
        $d = $r->validate(['message' => 'required|string|max:16000', 'conversation_id' => 'nullable|integer', 'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $r->user()->id)], 'assistant_id' => ['nullable', 'integer', Rule::exists('assistants', 'id')->where('user_id', $r->user()->id)], 'attachment_ids' => 'sometimes|array|max:5', 'attachment_ids.*' => 'integer', 'temporary' => 'sometimes|boolean', 'web_search' => 'sometimes|boolean', 'deep_research' => 'sometimes|boolean', 'search_time_range' => 'sometimes|in:day,week,month,year', 'history' => 'sometimes|array|max:20', 'history.*.role' => 'required|in:user,assistant', 'history.*.content' => 'required|string|max:16000']);
        $temporary = ! empty($d['temporary']);
        abort_if($temporary && ! empty($d['conversation_id']), 422, 'Temporary chats cannot continue saved conversations.');
        $c = ! empty($d['conversation_id']) ? Conversation::where('user_id', $r->user()->id)->findOrFail($d['conversation_id']) : new Conversation(['user_id' => $r->user()->id, 'title' => mb_substr($d['message'], 0, 80), 'project_id' => $d['project_id'] ?? null, 'assistant_id' => $d['assistant_id'] ?? null]);
        $messages = $context->build($r->user(), $c, $d['message'], $d['attachment_ids'] ?? [], $temporary);
        if ($temporary) {
            $history = [];
            $input = $d['history'] ?? [];
            for ($i = 0; $i < count($input) - 1; $i++) {
                if ($input[$i]['role'] === 'user' && $input[$i + 1]['role'] === 'assistant') {
                    $history[] = $input[$i];
                    $history[] = $input[++$i];
                }
            }
            while (array_sum(array_map(fn ($m) => strlen($m['content']), $history)) > 32000) {
                $history = array_slice($history, 2);
            }
            $messages = [$messages[0], ...$history, $messages[count($messages) - 1]];
        }
        $lock = Cache::lock('chat-user:'.$r->user()->id, 300);
        abort_unless($lock->get(), 409, 'A request is already running.');
        $assistant = null;
        try {
            $research = app(WebResearch::class);
            $deep = ! empty($d['deep_research']);
            $options = ['time_range' => $d['search_time_range'] ?? null];
            $evidence = $deep ? $research->collect($d['message'], $options) : (! empty($d['web_search']) ? app(WebSearch::class)->search($d['message'], $options) : []);
            if ($evidence || ! empty($d['web_search'])) {
                $messages[0]['content'] .= $research->prompt($evidence, $deep);
            }
            $sources = $research->summaries($evidence);
            $question = null;
            if (! $temporary) {
                DB::transaction(function () use ($c, $d, $sources, &$question, &$assistant) {
                    $c->save();
                    $question = $c->messages()->create(['role' => 'user', 'content' => $d['message'], 'attachment_ids' => $d['attachment_ids'] ?? []]);
                    $assistant = $c->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'generating', 'sources' => $sources]);
                    $c->touch();
                });
            }
            $ticket = bin2hex(random_bytes(32));
            Cache::put('frontend-chat:'.hash('sha256', $ticket), ['user_id' => $r->user()->id, 'message_id' => $assistant?->id, 'conversation_id' => $temporary ? null : $c->id, 'lock_owner' => $lock->owner(), 'done' => false], 600);

            return ['ticket' => $ticket, 'messages' => $messages, 'conversation_id' => $temporary ? null : $c->id, 'user_message_id' => $question?->id, 'message_id' => $assistant?->id, 'sources' => $sources];
        } catch (Throwable $e) {
            $assistant?->update(['status' => 'failed']);
            $lock->release();
            throw $e;
        }
    }

    private function lease(Request $r): array
    {
        $this->guard($r);
        $d = $r->validate(['ticket' => 'required|string|size:64']);
        $key = 'frontend-chat:'.hash('sha256', $d['ticket']);
        $lease = Cache::get($key);
        abort_unless($lease && $lease['user_id'] === $r->user()->id, 410, 'Chat lease expired.');

        return [$key, $lease];
    }

    public function status(Request $r)
    {
        [, $lease] = $this->lease($r);
        $cancelled = $lease['message_id'] && (! Message::whereKey($lease['message_id'])->where('status', 'generating')->exists() || Cache::get('stop-message:'.$lease['message_id']));

        return ['cancelled' => (bool) $cancelled, 'done' => $lease['done']];
    }

    public function finish(Request $r)
    {
        [$key] = $this->lease($r);
        $d = $r->validate(['content' => 'present|nullable|string|max:262144', 'status' => 'required|in:complete,failed,cancelled']);
        $d['content'] = $d['content'] ?? '';
        abort_if($d['status'] === 'complete' && trim($d['content']) === '', 422, 'Completed answer is empty.');
        abort_if(strlen($d['content']) > 262144, 422, 'Response exceeds storage limit.');

        return Cache::lock($key.':finish', 20)->block(3, function () use ($key, $r, $d) {
            $lease = Cache::get($key);
            abort_unless($lease && $lease['user_id'] === $r->user()->id, 410);
            if ($lease['done']) {
                return ['stored' => true, 'status' => $lease['status']];
            }
            if ($lease['message_id']) {
                $m = Message::whereKey($lease['message_id'])->whereHas('conversation', fn ($q) => $q->where('user_id', $r->user()->id))->first();
                // Message ownership is checked via its conversation below; deletion is never undone.
                if ($m) {
                    $m->update(['content' => $d['content'], 'status' => $d['status']]);
                }
            }
            $lease['done'] = true;
            $lease['status'] = $d['status'];
            Cache::put($key, $lease, 600);
            Cache::restoreLock('chat-user:'.$r->user()->id, $lease['lock_owner'])->release();

            return ['stored' => true, 'status' => $d['status']];
        });
    }
}
