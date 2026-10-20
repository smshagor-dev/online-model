<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $guarded = ['id'];

    public function members()
    {
        return $this->belongsToMany(User::class, 'team_members')->withPivot('role')->withTimestamps();
    }
}
