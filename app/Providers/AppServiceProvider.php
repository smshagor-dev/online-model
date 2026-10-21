<?php

namespace App\Providers;

use App\Services\AdminSettings;
use App\Services\SmtpSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->beforeResolving('mail.manager', function () {
            app(SmtpSettings::class)->apply();
        });
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn ($r) => [Limit::perMinute(5)->by('auth:'.$r->ip()), Limit::perHour(20)->by('email:'.hash('sha256', strtolower((string) $r->input('email'))))]);
        RateLimiter::for('hub', fn ($r) => Limit::perMinute(10)->by('hub:'.$r->user()->id));
        RateLimiter::for('api', fn ($r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('sensitive', fn ($r) => Limit::perMinute(5)->by('sensitive:'.$r->user()->id));
        RateLimiter::for('media', fn ($r) => Limit::perMinute(20)->by('media:'.$r->user()->id));
        RateLimiter::for('chat', function ($r) {
            $limits = [Limit::perMinute(10)->by('chat:'.$r->user()->id)];
            $daily = app(AdminSettings::class)->limit('chat_daily_limit');
            if ($daily > 0) {
                $limits[] = Limit::perDay($daily)->by('chat-day:'.$r->user()->id);
            }

            return $limits;
        });
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
