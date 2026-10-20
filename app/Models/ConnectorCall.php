<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectorCall extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['arguments' => 'encrypted:array', 'result' => 'encrypted:array'];
    }
}
