<?php

namespace App\Jobs;

use App\Models\MediaTask;
use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\CloudImageEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RunCloudImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $taskId) {}

    public function handle(CloudImageEngine $engine): void
    {
        $task = MediaTask::find($this->taskId);
        if (! $task || ! MediaTask::whereKey($task->id)->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now()])) {
            return;
        }
        $path = 'media/'.$task->user_id.'/'.$task->id.'.output';
        try {
            $user = User::find($task->user_id);
            if (! $user || ! $user->is_active) {
                throw new RuntimeException('Image account is unavailable.');
            }
            $options = $task->cloud_options ?? [];
            $mode = $options['mode'] ?? 'generate';
            $ratio = $options['aspect_ratio'] ?? '1:1';
            $engine->options($mode, $ratio);
            $result = $engine->render($task);

            DB::transaction(function () use ($task, $path, $result) {
                $current = MediaTask::whereKey($task->id)->lockForUpdate()->first();
                if (! $current || $current->status !== 'running') {
                    return;
                }
                abort_unless(Storage::disk('local')->put($path, $result['binary']), 503, 'Cannot save the generated image.');
                $current->update([
                    'status' => 'complete', 'output_path' => $path,
                    'output_mime' => $result['mime'], 'bytes' => strlen($result['binary']),
                    'error' => null,
                ]);
                $credits = app(ApiCreditUsage::class);
                $connection = $credits->connection('nvidia', $result['model'], 'image');
                $credits->completed($task->user_id, $connection, 'media:'.$task->id);
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            $this->failed($error);
        } finally {
            Storage::disk('local')->delete(array_filter([$task->input_path, $task->cloud_mask_path]));
            MediaTask::whereKey($task->id)->update(['input_path' => null, 'cloud_mask_path' => null]);
        }
    }

    public function failed(?Throwable $error): void
    {
        $task = MediaTask::find($this->taskId);
        if (! $task) {
            return;
        }
        Storage::disk('local')->delete(array_filter([$task->input_path, $task->cloud_mask_path]));
        MediaTask::whereKey($task->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => 'Image generation could not be completed. Check your configured model and try again.', 'input_path' => null, 'cloud_mask_path' => null]);
    }
}
