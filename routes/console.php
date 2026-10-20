<?php

use App\Jobs\RunMediaTask;
use App\Jobs\RunWorkspaceTask;
use App\Models\ConversationShare;
use App\Models\MediaTask;
use App\Models\Message;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('admin:create {email} {name}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists.');

        return 1;
    }
    $password = $this->secret('Password (12+ characters, uppercase, lowercase and number)');
    if (! is_string($password) || strlen($password) < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
        $this->error('Password does not meet policy.');

        return 1;
    }
    $u = User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => $password]);
    $u->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
    $this->info('Administrator created. Enable 2FA using the account API.');
})->purpose('Create an administrator without a default password');
Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::call(fn () => Message::where('status', 'generating')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed']))->everyMinute();
Schedule::call(fn () => ConversationShare::where('expires_at', '<', now())->delete())->daily();
Schedule::call(fn () => DB::table('team_invites')->where('expires_at', '<', now())->delete())->daily();

// A killed queue process cannot run its failure hook. Clean abandoned media inputs.
Schedule::call(function () {
    MediaTask::where(function ($q) {
        $q->where('status', 'running')->where('updated_at', '<', now()->subMinutes(20));
    })->orWhere(function ($q) {
        $q->where('status', 'queued')->where('created_at', '<', now()->subDay());
    })->eachById(fn ($task) => (new RunMediaTask($task->id))->failed(null));
})->everyFiveMinutes();

Schedule::call(function () {
    ScheduledTask::where('enabled', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->select('id')->chunkById(100, function ($tasks) {
        foreach ($tasks as $task) {
            DB::transaction(function () use ($task) {
                $t = ScheduledTask::lockForUpdate()->find($task->id);
                if (! $t || ! $t->enabled || ! $t->next_run_at || $t->next_run_at->isFuture()) {
                    return;
                }
                $active = TaskRun::where('scheduled_task_id', $t->id)->whereIn('status', ['queued', 'running'])->exists();
                $daily = TaskRun::whereIn('scheduled_task_id', ScheduledTask::where('user_id', $t->user_id)->select('id'))->whereDate('created_at', today())->count();
                $dailyLimit = app(\App\Services\AdminSettings::class)->limit('task_daily_limit');
                if ($active) {
                    $t->update(['next_run_at' => now()->addMinutes(5)]);

                    return;
                }
                if ($daily >= $dailyLimit) {
                    $t->update(['next_run_at' => now()->addDay()->startOfDay()]);

                    return;
                }
                if (! $active && $daily < $dailyLimit) {
                    $run = TaskRun::create(['scheduled_task_id' => $t->id, 'steps' => [['label' => 'Scheduled run queued', 'status' => 'queued']]]);
                    RunWorkspaceTask::dispatch($run->id)->onQueue('tasks')->afterCommit();
                }
                $t->update(['enabled' => $t->frequency !== 'once', 'next_run_at' => match ($t->frequency) {
                    'daily' => now()->addDay(),'weekly' => now()->addWeek(),default => null
                }]);
            });
        }
    });
})->name('workspace-task-dispatch')->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    TaskRun::where('status', 'running')->where('updated_at', '<', now()->subMinutes(10))->update(['status' => 'failed', 'error' => 'Task worker stopped before completing.']);
    TaskRun::where('status', 'queued')->where('updated_at', '<', now()->subDay())->update(['status' => 'failed', 'error' => 'Task worker did not start in time.']);
})->everyFiveMinutes();
