<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $attributes = ['is_active' => true, 'is_admin' => false, 'memory_enabled' => false, 'theme' => 'dark'];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean', 'memory_enabled' => 'boolean', 'is_active' => 'boolean', 'email_verified_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime', 'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'encrypted:array'];
    }
}
