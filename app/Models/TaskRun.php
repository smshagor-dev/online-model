<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'sources' => 'array'];
    }
}
