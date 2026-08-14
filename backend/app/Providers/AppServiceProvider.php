<?php

namespace App\Providers;

use App\Services\Contracts\OtpSender;
use App\Services\Otp\FailingOtpSender;
use App\Services\Otp\LogOtpSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpSender::class, function () {
            return match (config('services.internal.otp.delivery_driver')) {
                'log' => new LogOtpSender,
                default => new FailingOtpSender,
            };
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
