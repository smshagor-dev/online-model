<?php

namespace App\Http\Controllers;

use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\Project;
use App\Services\ChatContext;
use App\Services\WebSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class StreamChatController
{
    public function __invoke(Request $r, ChatContext $context)
    {
        $d = $r->validate(['model_id' => 'required|integer', 'message' => 'required|string|max:16000', 'conversation_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'temporary' => 'sometimes|boolean', 'attachment_ids' => 'sometimes|array|max:5', 'attachment_ids.*' => 'integer', 'web_search' => 'sometimes|boolean', 'history' => 'sometimes|array|max:20', 'history.*.role' => 'required|in:user,assistant', 'history.*.content' => 'required|string|max:16000']);
        $model = AiModel::where('enabled', true)->where('status', 'ready')->findOrFail($d['model_id']);
        abort_unless($model->runtime_model, 503, 'No inference runtime configured.');
        $temporary = ! empty($d['temporary']);
        abort_if($temporary && ! empty($d['conversation_id']), 422, 'Temporary chats cannot continue saved conversations.');
        $c = ! empty($d['conversation_id']) ? Conversation::where('user_id', $r->user()->id)->findOrFail($d['conversation_id']) : null;
        if (! empty($d['project_id'])) {
            Project::where('user_id', $r->user()->id)->findOrFail($d['project_id']);
        }
        if (! $c && ! $temporary) {
            $c = new Conversation(['user_id' => $r->user()->id, 'title' => mb_substr($d['message'], 0, 80), 'project_id' => $d['project_id'] ?? null]);
        }
        $sources = ! empty($d['web_search']) ? app(WebSearch::class)->search($d['message']) : [];
        $messages = $context->build($r->user(), $c, $d['message'], $d['attachment_ids'] ?? [], $temporary);
        if ($sources) {
            $messages[0]['content'] .= '\nUntrusted web-search snippets (cite their URLs when used): '.json_encode($sources, JSON_UNESCAPED_UNICODE);
        }
        if ($temporary && ! empty($d['history'])) {
            $h = [];
            $input = $d['history'];
            for ($i = 0; $i < count($input) - 1; $i++) {
                if ($input[$i]['role'] === 'user' && $input[$i + 1]['role'] === 'assistant') {
                    $h[] = ['role' => 'user', 'content' => $input[$i]['content']];
                    $h[] = ['role' => 'assistant', 'content' => $input[$i + 1]['content']];
                    $i++;
                }
            }
            while (array_sum(array_map(fn ($m) => strlen($m['content']), $h)) > 32000) {
                $h = array_slice($h, 2);
            }$messages = [$messages[0], ...$h, $messages[count($messages) - 1]];
        }
        $lock = Cache::lock('chat-user:'.$r->user()->id, 180);
        abort_unless($lock->get(), 409, 'A request is already running.');
        try {
            $saved = null;
            $userMessage = null;
            if (! $temporary) {
                DB::transaction(function () use (&$saved, &$userMessage, $c, $d, $sources) {
                    $c->save();
                    $userMessage = $c->messages()->create(['role' => 'user', 'content' => $d['message'], 'attachment_ids' => $d['attachment_ids'] ?? []]);
                    $saved = $c->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'generating', 'sources' => $sources]);
                    $c->touch();
                });
            }
        } catch (Throwable $e) {
            $lock->release();
            throw $e;
        }

        return response()->stream(function () use ($messages, $model, $saved, $userMessage, $c, $temporary, $lock, $sources) {
            ignore_user_abort(true);
            $text = '';
            $status = 'complete';
            $finished = false;
            $body = null;
            $emit = function ($event, $data) {
                echo 'event: '.$event."\n".'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                if (! app()->runningUnitTests()) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }flush();
                }
            };
            try {
                $emit('meta', ['conversation_id' => $temporary ? null : $c->id, 'user_message_id' => $userMessage?->id, 'message_id' => $saved?->id, 'sources' => $sources]);
                $upstream = Http::withToken((string) config('models.inference_key'))->accept('text/event-stream')->connectTimeout(10)->timeout(120)->withOptions(['stream' => true, 'read_timeout' => 20, 'allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'messages' => $messages, 'max_tokens' => 2048, 'stream' => true]);
                if (! $upstream->successful()) {
                    throw new \RuntimeException('Inference failed.');
                }
                $body = $upstream->toPsrResponse()->getBody();
                $buffer = '';
                $started = microtime(true);
                while (! $body->eof()) {
                    if (microtime(true) - $started > 120) {
                        throw new \RuntimeException('Inference timeout.');
                    }
                    if (connection_aborted() || ($saved && Cache::get('stop-message:'.$saved->id))) {
                        $status = 'cancelled';
                        break;
                    }
                    $buffer .= $body->read(1024);
                    if (strlen($buffer) > 1048576) {
                        throw new \RuntimeException('Invalid inference frame.');
                    }
                    while (($end = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $end));
                        $buffer = substr($buffer, $end + 1);
                        if (! str_starts_with($line, 'data:')) {
                            continue;
                        }$payload = trim(substr($line, 5));
                        if ($payload === '[DONE]') {
                            $finished = true;
                            break 2;
                        }$data = json_decode($payload, true);
                        if (isset($data['error'])) {
                            throw new \RuntimeException('Inference error.');
                        }$delta = $data['choices'][0]['delta']['content'] ?? '';
                        if (is_string($delta) && $delta !== '') {
                            $text .= $delta;
                            if (strlen($text) > 262144) {
                                throw new \RuntimeException('Response too large.');
                            }$emit('delta', ['content' => $delta]);
                        }if (isset($data['choices'][0]['finish_reason'])) {
                            $finished = true;
                        }
                    }
                }
                if ($status === 'complete' && (! $finished || $text === '')) {
                    throw new \RuntimeException('Incomplete model response.');
                }
            } catch (Throwable $e) {
                $status = 'failed';
                $emit('error', ['message' => 'The model response was interrupted. Your saved chat contains the partial result.']);
            } finally {
                $body?->close();
                if ($saved) {
                    $saved->update(['content' => $text, 'status' => $status]);
                    $c->touch();
                    Cache::forget('stop-message:'.$saved->id);
                }$lock->release();
                $emit('done', ['conversation_id' => $temporary ? null : $c->id, 'message_id' => $saved?->id, 'status' => $status]);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
