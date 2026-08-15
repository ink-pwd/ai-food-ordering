<?php

namespace App\Providers;

use App\Telegram\Session\TelegramSessionStore;
use Illuminate\Support\ServiceProvider;
use App\Telegram\Support\DeliveryAddressPromptStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramSessionStore::class);
        $this->app->singleton(DeliveryAddressPromptStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
