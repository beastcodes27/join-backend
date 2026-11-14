<?php

namespace App\Providers;

use App\Contracts\IdentityVerifier;
use App\Services\FirebaseIdentityVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityVerifier::class, FirebaseIdentityVerifier::class);
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn ($r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('join', fn ($r) => [Limit::perMinute(30)->by('join-session:'.hash('sha256', $r->session()->getId())), Limit::perMinute(300)->by('join-ip:'.$r->ip())]);
        RateLimiter::for('api', fn ($r) => [Limit::perMinute(120)->by('api-actor:'.($r->user()?->id ?? hash('sha256', $r->session()->getId()))), Limit::perMinute(600)->by('api-ip:'.$r->ip())]);
        RateLimiter::for('create', fn ($r) => Limit::perHour(20)->by($r->user()?->id ?? $r->ip()));
    }
}
