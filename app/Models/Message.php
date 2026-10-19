<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = ['id'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return ['attachment_ids' => 'array', 'sources' => 'array'];
    }
}
