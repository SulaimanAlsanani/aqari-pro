<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Fragment never reaches the web server or its request logs. The
        // same-origin staging JS immediately removes it from browser history.
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            return URL::to('/staging/').'#reset='.
                rawurlencode($token).'&email='.
                rawurlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
