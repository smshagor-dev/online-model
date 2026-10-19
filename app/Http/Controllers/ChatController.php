<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\Conversation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ChatController
{
    public function models()
    {
        return AiModel::where('enabled', true)->where('status', 'ready')->get(['id', 'name', 'family', 'license']);
    }

    public function index(Request $r)
    {
        return Conversation::where('user_id', $r->user()->id)->latest()->paginate(20);
    }

    public function show(Request $r, int $id)
    {
        $conversation = Conversation::where('user_id', $r->user()->id)->findOrFail($id);

        return ['conversation' => $conversation, 'messages' => $conversation->messages()->orderBy('id')->paginate(50)];
    }

    public function delete(Request $r, int $id)
    {
        Conversation::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function send(Request $r)
    {
        $d = $r->validate(['model_id' => 'required|integer', 'conversation_id' => 'nullable|integer', 'message' => 'required|string|max:16000', 'max_tokens' => 'sometimes|integer|min:1|max:2048']);
        $model = AiModel::where('enabled', true)->where('status', 'ready')->findOrFail($d['model_id']);
        abort_unless($model->runtime_model, 503, 'Model is not connected to inference.');
        $lock = Cache::lock('chat-user:'.$r->user()->id, 150);
        abort_unless($lock->get(), 409, 'A request is already running.');
        try {
            $c = isset($d['conversation_id']) ? Conversation::where('user_id', $r->user()->id)->findOrFail($d['conversation_id']) : null;
            $history = $c ? $c->messages()->latest('id')->limit(20)->get()->reverse()->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all() : [];
            while (array_sum(array_map(fn ($m) => strlen($m['content']), $history)) > 32000) {
                array_shift($history);
            }
            $history[] = ['role' => 'user', 'content' => $d['message']];
            try {
                $response = Http::withToken((string) config('models.inference_key'))->acceptJson()->connectTimeout(10)->timeout(120)->withOptions(['allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'messages' => $history, 'max_tokens' => $d['max_tokens'] ?? 1024, 'stream' => false]);
            } catch (ConnectionException $e) {
                abort(503, 'Inference server unavailable.');
            }
            abort_unless($response->successful(), 502, 'Inference request failed.');
            $content = $response->json('choices.0.message.content');
            abort_unless(is_string($content) && trim($content) !== '' && strlen($content) <= 262144, 502, 'Invalid inference response.');
            $c = DB::transaction(function () use ($c, $r, $d, $content) {
                $c ??= Conversation::create(['user_id' => $r->user()->id, 'title' => mb_substr($d['message'], 0, 80)]);
                $c->messages()->createMany([['role' => 'user', 'content' => $d['message']], ['role' => 'assistant', 'content' => $content]]);
                $c->touch();

                return $c;
            });

            return ['conversation_id' => $c->id, 'message' => ['role' => 'assistant', 'content' => $content]];
        } finally {
            $lock->release();
        }
    }
}
