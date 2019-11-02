<?php

namespace StingBo\Mengine;

use Illuminate\Support\ServiceProvider;
use StingBo\Mengine\Services\MengineService;

class MengineServiceProvider extends ServiceProvider
{
    protected $defer = true;

    /**
     * boot.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/mengine.php' => config_path('mengine.php'),
        ]);
    }

    /**
     * register.
     */
    public function register(): void
    {
        $this->app->singleton(MengineService::class, function () {
            return new MengineService();
        });
    }
}
