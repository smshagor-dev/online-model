<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectorAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credential', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['credential' => 'encrypted'];
    }
}
