<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\ChatContext;
use App\Services\ResearchCompletion;
use App\Services\TaskCheckpoint;
use App\Services\WebResearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RunWorkspaceTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public ?string $executionToken = null;

    public function __construct(public int $runId)
    {
        $this->executionToken = TaskRun::whereKey($runId)->where('status', 'queued')->value('execution_token') ?: (string) Str::uuid();
    }

    public function handle(): void
    {
        $this->executionToken ??= (string) Str::uuid();
        if (! TaskRun::whereKey($this->runId)->where('status', 'queued')->where(fn ($q) => $q->whereNull('execution_token')->orWhere('execution_token', $this->executionToken))->update(['status' => 'running', 'execution_token' => $this->executionToken])) {
            return;
        }
        $run = TaskRun::findOrFail($this->runId);
        $store = app(TaskCheckpoint::class);
        $token = $this->executionToken;
        $active = fn () => $store->active($run->id, $token);
        try {
            $task = ScheduledTask::find($run->scheduled_task_id);
            $user = $task ? User::find($task->user_id) : null;
            $model = $task ? AiModel::where('enabled', true)->where('status', 'ready')->find($task->model_id) : null;
            if (! $user?->is_active || ! $model?->runtime_model) {
                throw new \RuntimeException('Task owner or runtime unavailable.');
            }
            $state = $run->checkpoint ?? [];
            $signature = TaskCheckpoint::signature($task);
            if (isset($state['task_signature']) && $state['task_signature'] !== $signature) {
                throw new \RuntimeException('Task definition changed.');
            }
            $persist = fn ($saved, $attributes = []) => $store->save($run->id, $token, [...$saved, 'task_signature' => $signature], $attributes);
            $steps = $run->steps ?? [];
            $sources = $state['sources'] ?? [];
            if ($task->kind === 'research' && ! isset($state['stage'])) {
                $sources = app(WebResearch::class)->collect($task->prompt, [], function ($label, $status, $evidence) use (&$steps, $run, $token) {
                    $steps[] = ['label' => $label, 'status' => $status];
                    TaskRun::whereKey($run->id)->where('status', 'running')->where('execution_token', $token)->update(['steps' => $steps, 'sources' => app(WebResearch::class)->summaries($evidence)]);
                }, fn () => ! $active());
                $state = ['stage' => 'research', 'sources' => $sources, 'actions' => []];
                $persist($state);
            }
            if ($task->kind === 'research' && ($state['stage'] ?? '') === 'research') {
                $coverage = app(ResearchCompletion::class)->run($task->prompt, $sources, $model->runtime_model, function ($label, $evidence) use (&$steps, $run, $token) {
                    $steps[] = ['label' => $label, 'status' => 'complete'];
                    TaskRun::whereKey($run->id)->where('status', 'running')->where('execution_token', $token)->update(['steps' => $steps, 'sources' => app(WebResearch::class)->summaries($evidence)]);
                }, fn () => ! $active(), $state, function ($saved) use ($persist) {
                    $persist($saved);
                });
                $sources = $coverage['sources'];
                $state = ['stage' => 'writing', 'sources' => $sources, 'conclusion' => $coverage['conclusion']];
            }
            if (! $active()) {
                return;
            }
            if (! isset($state['messages'])) {
                $messages = app(ChatContext::class)->build($user, null, $task->prompt);
                if ($sources) {
                    $messages[0]['content'] .= app(WebResearch::class)->prompt($sources)."\nCoverage review: ".($state['conclusion'] ?? '');
                }
                $state = [...$state, 'stage' => 'writing', 'messages' => $messages, 'result' => '', 'previous_part' => ''];
                $persist($state);
            }
            $steps[] = ['label' => 'Generate answer', 'status' => 'running'];
            while (empty($state['finished'])) {
                if (! $active()) {
                    return;
                }
                $response = Http::withToken((string) config('models.inference_key'))->connectTimeout(10)->timeout(120)->withOptions(['allow_redirects' => false])->post(rtrim(config('models.inference_url'), '/').'/chat/completions', ['model' => $model->runtime_model, 'messages' => $state['messages'], 'stream' => false]);
                $part = $response->json('choices.0.message.content');
                $reason = $response->json('choices.0.finish_reason');
                if (! $response->successful() || ! is_string($part) || trim($part) === '' || ($reason && ! in_array($reason, ['stop', 'length'], true))) {
                    throw new \RuntimeException('Invalid runtime response.');
                }
                if ($reason === 'length' && $part === ($state['previous_part'] ?? '')) {
                    throw new \RuntimeException('Continuation repeated output without progress.');
                }
                $state['result'] .= $part;
                $state['previous_part'] = $part;
                $state['finished'] = $reason !== 'length';
                if (! $state['finished']) {
                    $state['messages'][] = ['role' => 'assistant', 'content' => $part];
                    $state['messages'][] = ['role' => 'user', 'content' => 'Continue exactly where the answer stopped. Finish all remaining parts without repeating earlier text.'];
                }
                $persist($state, ['result' => $state['result'], 'steps' => $steps]);
            }
            $steps[count($steps) - 1]['status'] = 'complete';
            TaskRun::whereKey($run->id)->where('status', 'running')->where('execution_token', $token)->update(['status' => 'complete', 'result' => $state['result'], 'sources' => app(WebResearch::class)->summaries($sources), 'steps' => $steps, 'checkpoint' => null, 'execution_token' => null, 'error' => null]);
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        $query = TaskRun::whereKey($this->runId);
        if ($this->executionToken) {
            $query->where('status', 'running')->where('execution_token', $this->executionToken);
        } else {
            return; // Legacy payloads have no claim identity; the scheduler detects abandonment.
        }
        $query->update(['status' => 'failed', 'execution_token' => null, 'error' => 'This task stopped before completion. Check the runtime/search service, then resume saved progress when available.']);
    }
}
