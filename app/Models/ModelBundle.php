<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelBundle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['files' => 'array', 'bytes' => 'integer'];
    }
}
