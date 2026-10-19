<?php

namespace App\Services;

use App\Models\ScheduledTask;
use App\Models\TaskRun;
use Illuminate\Support\Facades\Crypt;

class TaskCheckpoint
{
    public static function signature(ScheduledTask $task): string
    {
        return hash('sha256', json_encode([(string) $task->prompt, (int) $task->model_id, (string) $task->kind], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function save(int $id, string $token, array $state, array $attributes = []): bool
    {
        return TaskRun::whereKey($id)->where('status', 'running')->where('execution_token', $token)->update([...$attributes, 'checkpoint' => Crypt::encryptString(json_encode(['version' => 1, ...$state], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE))]) > 0;
    }

    public function active(int $id, string $token): bool
    {
        return TaskRun::whereKey($id)->where('status', 'running')->where('execution_token', $token)->exists();
    }
}
