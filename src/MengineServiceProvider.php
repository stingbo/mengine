<?php

namespace StingBo\Mengine;

use Illuminate\Support\ServiceProvider;
use StingBo\Mengine\Console\CleanupDepthCommand;

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
        if ($this->app->runningInConsole()) {
            $this->commands([CleanupDepthCommand::class]);
        }

        $this->publishes([
            __DIR__.'/../config/mengine.php' => config_path('mengine.php'),
        ]);
    }
}
