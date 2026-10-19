<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishedAssistant extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['user_id', 'assistant_id'];

    protected function casts(): array
    {
        return ['blocked' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
