<?php

namespace App\Providers;

use App\Contracts\FcmTokenRepository;
use App\Services\FirestoreFcmTokenRepository;
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
        //
    }
}
