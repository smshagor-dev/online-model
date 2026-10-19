<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $attributes = ['is_active' => true, 'is_admin' => false, 'memory_enabled' => false, 'theme' => 'dark', 'personalization_enabled' => true, 'response_style' => 'default', 'response_length' => 'default', 'response_language' => 'auto', 'accent_color' => 'default', 'text_size' => 'default', 'reduced_motion' => false, 'send_on_enter' => true, 'completion_notifications' => false];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'email_change_token', 'email_change_expires_at'];

    protected function casts(): array
    {
        return ['personalization_enabled' => 'boolean', 'reduced_motion' => 'boolean', 'send_on_enter' => 'boolean', 'completion_notifications' => 'boolean', 'email_change_expires_at' => 'datetime', 'password' => 'hashed', 'is_admin' => 'boolean', 'memory_enabled' => 'boolean', 'is_active' => 'boolean', 'email_verified_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime', 'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'encrypted:array'];
    }
}
