<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmtpSetting extends Model
{
    protected $table = 'smtp_settings';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
