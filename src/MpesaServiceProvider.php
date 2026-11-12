<?php

namespace Bmunyoki\Mpesa;

use Bmunyoki\Mpesa\Http\DarajaClient;
use Illuminate\Support\ServiceProvider;

class MpesaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom(__DIR__.'/../config/mpesa.php', 'mpesa');

        $this->app->singleton(DarajaClient::class, fn () => new DarajaClient());

        $this->app->singleton('bmunyoki-mpesa', function ($app) {
            return new Mpesa($app->make(DarajaClient::class));
        });

        $this->app->alias('bmunyoki-mpesa', Mpesa::class);
    }

    public function boot(): void {
        $this->publishes([
            __DIR__.'/../config/mpesa.php' => config_path('mpesa.php'),
        ], 'mpesa-config');
    }
}
