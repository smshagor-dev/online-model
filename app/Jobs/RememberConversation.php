<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\Message;
use App\Services\AutomaticMemory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class RememberConversation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 1;

    public function __construct(public int $questionId, public int $answerId, public int $modelId) {}

    public function handle(): void
    {
        $question = Message::with('conversation.user')->find($this->questionId);
        $answer = Message::find($this->answerId);
        $user = $question?->conversation?->user;
        $model = AiModel::where('enabled', true)->where('status', 'ready')->find($this->modelId);
        if (! $user?->is_active || ! $user->memory_enabled || ! $user->automatic_memory || ! $model?->runtime_model || ! $answer || $answer->conversation_id !== $question->conversation_id || $answer->status !== 'complete' || $answer->role !== 'assistant' || $question->role !== 'user' || $answer->id <= $question->id) {
            return;
        }
        $r = Http::withToken((string) config('models.inference_key'))->connectTimeout(5)->timeout(30)->withOptions(['allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'stream' => false, 'max_tokens' => 512, 'messages' => [['role' => 'system', 'content' => AutomaticMemory::INSTRUCTION], ['role' => 'user', 'content' => $question->content]]]);
        $data = json_decode((string) $r->json('choices.0.message.content'), true);
        if ($r->successful() && is_array($data) && array_is_list($data)) {
            app(AutomaticMemory::class)->remember($user, $data);
        }
    }
}
