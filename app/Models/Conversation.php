<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
