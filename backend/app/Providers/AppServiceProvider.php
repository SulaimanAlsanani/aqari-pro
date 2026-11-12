<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Separate credential and second-factor limits. A single global IP
        // limiter must not consume both budgets during a normal MFA login.
        RateLimiter::for('aqari-password-login', fn (Request $request) =>
            Limit::perMinute(5)->by('aqari-password:'.$request->ip())
        );
        RateLimiter::for('aqari-mfa-challenge', fn (Request $request) =>
            Limit::perMinute(5)->by(
                'aqari-mfa:'.$request->ip().':'.
                (int) $request->session()->get('mfa_pending_user_id', 0)
            )
        );

        // Fragment never reaches the web server or its request logs. The
        // same-origin staging JS immediately removes it from browser history.
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            return URL::to('/staging').'/#reset='.
                rawurlencode($token).'&email='.
                rawurlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
