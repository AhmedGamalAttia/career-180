<?php

namespace App\Providers;

use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\PaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The payout provider is resolved through this binding everywhere, so
        // tests can swap in a deterministic fake while production uses the mock.
        $this->app->singleton(PaymentProvider::class, function () {
            return new MockPaymentProvider(Cache::store());
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
