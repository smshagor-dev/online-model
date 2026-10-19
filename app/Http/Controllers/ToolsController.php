<?php

namespace App\Http\Controllers;

use App\Jobs\RunWorkspaceTask;
use App\Models\Assistant;
use App\Models\Conversation;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ToolsController
{
    public function assistants(Request $r)
    {
        return Assistant::where('user_id', $r->user()->id)->latest()->get();
    }

    public function saveAssistant(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:500', 'instructions' => 'required|string|max:8000', 'model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')->where('enabled', true)->where('status', 'ready')], 'attachment_ids' => 'sometimes|array|max:5', 'attachment_ids.*' => ['integer', Rule::exists('attachments', 'id')->where('user_id', $r->user()->id)]]);
        abort_if(! $id && Assistant::where('user_id', $r->user()->id)->count() >= 50, 422, 'Assistant limit reached.');
        $a = $id ? Assistant::where('user_id', $r->user()->id)->findOrFail($id) : new Assistant(['user_id' => $r->user()->id]);
        $a->fill($d)->save();

        return $a;
    }

    public function deleteAssistant(Request $r, int $id)
    {
        Assistant::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function tasks(Request $r)
    {
        return ScheduledTask::where('user_id', $r->user()->id)->latest()->get();
    }

    public function saveTask(Request $r, ?int $id = null)
    {
        $d = $r->validate(['title' => 'required|string|max:120', 'prompt' => 'required|string|max:16000', 'model_id' => ['required', 'integer', Rule::exists('ai_models', 'id')->where('enabled', true)->where('status', 'ready')], 'kind' => 'required|in:chat,research', 'frequency' => 'required|in:once,daily,weekly', 'next_run_at' => 'nullable|date|after:now', 'enabled' => 'required|boolean']);
        abort_if(! $id && ScheduledTask::where('user_id', $r->user()->id)->count() >= 50, 422, 'Task limit reached.');
        $t = $id ? ScheduledTask::where('user_id', $r->user()->id)->findOrFail($id) : new ScheduledTask(['user_id' => $r->user()->id]);
        $t->fill($d)->save();

        return $t;
    }

    public function deleteTask(Request $r, int $id)
    {
        ScheduledTask::where('user_id', $r->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function runs(Request $r, int $id)
    {
        ScheduledTask::where('user_id', $r->user()->id)->findOrFail($id);

        return TaskRun::where('scheduled_task_id', $id)->latest()->paginate(20);
    }

    public function run(Request $r, int $id)
    {
        $t = ScheduledTask::where('user_id', $r->user()->id)->findOrFail($id);

        return Cache::lock('workspace-task:'.$id, 10)->block(2, fn () => DB::transaction(function () use ($t, $r) {
            $t = ScheduledTask::where('user_id', $r->user()->id)->lockForUpdate()->findOrFail($t->id);
            abort_if(TaskRun::where('scheduled_task_id', $t->id)->whereIn('status', ['queued', 'running'])->exists(), 409, 'This task is already active.');
            abort_if(config('models.task_daily_limit') > 0 && TaskRun::whereIn('scheduled_task_id', ScheduledTask::where('user_id', $r->user()->id)->select('id'))->whereDate('created_at', today())->count() >= config('models.task_daily_limit'), 429, 'Daily task limit reached.');
            $run = TaskRun::create(['scheduled_task_id' => $t->id, 'steps' => [['label' => 'Waiting for worker', 'status' => 'queued']]]);
            RunWorkspaceTask::dispatch($run->id)->onQueue('tasks');

            return response()->json($run, 202);
        }));
    }

    public function cancelRun(Request $r, int $id, int $run)
    {
        ScheduledTask::where('user_id', $r->user()->id)->findOrFail($id);
        TaskRun::where('scheduled_task_id', $id)->whereIn('status', ['queued', 'running'])->findOrFail($run)->update(['status' => 'cancelled']);

        return response()->noContent();
    }

    public function branches(Request $r, int $id)
    {
        $all = Conversation::where('user_id', $r->user()->id)->get(['id', 'parent_id', 'title', 'updated_at']);
        $current = $all->firstWhere('id', $id);
        abort_unless($current, 404);
        $root = $current;
        $seen = [];
        while ($root->parent_id && ! in_array($root->id, $seen, true)) {
            $seen[] = $root->id;
            $parent = $all->firstWhere('id', $root->parent_id);
            if (! $parent) {
                break;
            } $root = $parent;
        }
        $ids = [$root->id];
        do {
            $before = count($ids);
            foreach ($all as $c) {
                if (in_array($c->parent_id, $ids, true) && ! in_array($c->id, $ids, true)) {
                    $ids[] = $c->id;
                }
            }
        } while (count($ids) > $before);

        return $all->whereIn('id', $ids)->values();
    }
}
