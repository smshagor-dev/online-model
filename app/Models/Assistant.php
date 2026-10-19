<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assistant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attachment_ids' => 'array'];
    }
}
