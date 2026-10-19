<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationShare extends Model
{
    protected $guarded = ['id'];

    protected $table = 'conversation_shares';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'expires_at' => 'datetime'];
    }
}
