<?php

namespace App\Providers;

use App\Telegram\Session\TelegramSessionStore;
use App\Telegram\Support\DeliveryAddressPromptStore;
use App\Telegram\Support\OrderTrackingPromptStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramSessionStore::class);
        $this->app->singleton(DeliveryAddressPromptStore::class);
        $this->app->singleton(OrderTrackingPromptStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
