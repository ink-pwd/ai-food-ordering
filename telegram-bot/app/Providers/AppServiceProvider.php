<?php

namespace App\Providers;

use App\Contracts\AiToolExecutor;
use App\Contracts\LlmClient;
use App\Integrations\Groq\GroqLlmClient;
use App\Services\Ai\BackendAiToolExecutor;
use App\Telegram\Session\TelegramSessionStore;
use App\Telegram\Support\AiConversationStore;
use App\Telegram\Support\AiPromptStore;
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
        $this->app->bind(LlmClient::class, GroqLlmClient::class);
        $this->app->bind(AiToolExecutor::class, BackendAiToolExecutor::class);
        $this->app->singleton(TelegramSessionStore::class);
        $this->app->singleton(AiPromptStore::class);
        $this->app->singleton(AiConversationStore::class);
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
