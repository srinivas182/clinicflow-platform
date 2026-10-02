<?php

namespace App\Providers;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Support\LogOtpSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OtpSender::class, LogOtpSender::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower($request->string('login')->toString()).'|'.$request->ip()));
        RateLimiter::for('login-code', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
