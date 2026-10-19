<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskRun extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['checkpoint', 'execution_token'];

    protected $appends = ['resumable'];

    public function getResumableAttribute(): bool
    {
        return ! empty($this->attributes['checkpoint']) && in_array($this->status, ['failed', 'cancelled'], true);
    }

    protected function casts(): array
    {
        return ['steps' => 'array', 'sources' => 'array', 'checkpoint' => 'encrypted:array'];
    }
}
