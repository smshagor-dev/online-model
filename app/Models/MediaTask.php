<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MediaTask extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['input_path', 'output_path'];

    protected static function booted(): void
    {
        static::deleted(function (self $task) {
            Storage::disk('local')->delete(array_filter([$task->input_path, $task->output_path]));
        });
    }
}
