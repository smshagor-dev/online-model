<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ChatContext;
use App\Services\ResearchCompletion;
use App\Services\WebResearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class RunWorkspaceTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = TaskRun::find($this->runId);
        if (! $run || ! TaskRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'running'])) {
            return;
        }
        try {
            $task = ScheduledTask::find($run->scheduled_task_id);
            $user = $task ? User::find($task->user_id) : null;
            $model = $task ? AiModel::where('enabled', true)->where('status', 'ready')->find($task->model_id) : null;
            if (! $user?->is_active || ! $model?->runtime_model) {
                throw new \RuntimeException('Task owner or runtime unavailable.');
            }
            $sources = [];
            $steps = [['label' => 'Prepare request', 'status' => 'complete']];
            if ($task->kind === 'research') {
                $sources = app(WebResearch::class)->collect($task->prompt, [], function ($label, $status, $evidence) use (&$steps, $run) {
                    $steps[] = ['label' => $label, 'status' => $status];
                    TaskRun::whereKey($run->id)->where('status', 'running')->update(['steps' => $steps, 'sources' => app(WebResearch::class)->summaries($evidence)]);
                }, fn () => TaskRun::find($run->id)?->status !== 'running');
            }
            if ($task->kind === 'research') {
                $coverage = app(ResearchCompletion::class)->run($task->prompt, $sources, $model->runtime_model, function ($label, $evidence) use (&$steps, $run) {
                    $steps[] = ['label' => $label, 'status' => 'complete'];
                    TaskRun::whereKey($run->id)->where('status', 'running')->update(['steps' => $steps, 'sources' => app(WebResearch::class)->summaries($evidence)]);
                }, fn () => TaskRun::find($run->id)?->status !== 'running');
                $sources = $coverage['sources'];
            }
            if (TaskRun::find($run->id)?->status !== 'running') {
                return;
            }
            $steps[] = ['label' => 'Generate answer', 'status' => 'running'];
            $run->update(['steps' => $steps]);
            $messages = app(ChatContext::class)->build($user, null, $task->prompt);
            if ($sources) {
                $messages[0]['content'] .= app(WebResearch::class)->prompt($sources).'\nCoverage review: '.($coverage['conclusion'] ?? '');
            }
            $result = '';
            $previousPart = '';
            do {
                if (TaskRun::find($run->id)?->status !== 'running') {
                    return;
                }
                $response = Http::withToken((string) config('models.inference_key'))->connectTimeout(10)->timeout(120)->withOptions(['allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'messages' => $messages, 'stream' => false]);
                $part = $response->json('choices.0.message.content');
                if (! $response->successful() || ! is_string($part) || trim($part) === '') {
                    throw new \RuntimeException('Invalid runtime response.');
                }
                $result .= $part;
                $reason = $response->json('choices.0.finish_reason');
                if ($reason && ! in_array($reason, ['stop', 'length'], true)) {
                    throw new \RuntimeException('Runtime stopped before completion.');
                }
                TaskRun::whereKey($run->id)->where('status', 'running')->update(['result' => $result]);
                if ($reason === 'length') {
                    if ($part === $previousPart) {
                        throw new \RuntimeException('Continuation repeated output without progress.');
                    }
                    $previousPart = $part;
                    $messages[] = ['role' => 'assistant', 'content' => $part];
                    $messages[] = ['role' => 'user', 'content' => 'Continue exactly where the answer stopped. Finish all remaining parts of the original request without repeating earlier text.'];
                }
            } while ($reason === 'length');
            $steps[count($steps) - 1]['status'] = 'complete';
            TaskRun::whereKey($run->id)->where('status', 'running')->update(['status' => 'complete', 'result' => $result, 'sources' => app(WebResearch::class)->summaries($sources), 'steps' => $steps]);
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        TaskRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error' => 'This task could not complete. Check the model runtime, search service and queue worker.']);
    }
}
