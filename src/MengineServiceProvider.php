<?php

namespace StingBo\Mengine;

use Illuminate\Support\ServiceProvider;

class MengineServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register()
    {
        $this->app->singleton('mengine', function () {
            return $this->app->make('StingBo\Mengine\Services\MengineService');
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/mengine.php' => config_path('mengine.php'),
        ]);
    }
}
