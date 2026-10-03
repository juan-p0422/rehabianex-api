<?php

namespace App\Providers;

use App\Contracts\FcmTokenRepository;
use App\Services\FirestoreFcmTokenRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FcmTokenRepository::class, FirestoreFcmTokenRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('fcm-demo', function (Request $request): Limit {
            $uid = trim((string) $request->attributes->get('firebase_uid'));

            return Limit::perMinute(max(1, (int) config('fcm.demo.rate_limit_per_minute', 30)))
                ->by($uid !== '' ? 'firebase:'.$uid : 'unauthenticated');
        });
    }
}
