<?php

namespace StingBo\Mengine;

use Illuminate\Support\ServiceProvider;

class MengineServiceProvider extends ServiceProvider
{
    protected $defer = true;

    /**
     * boot.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/uploader.php' => config_path('uploader.php'),
        ]);
    }

    /**
     * register.
     */
    public function register(): void
    {
        $this->app->singleton(Mengine::class, function () {
            return new Mengine();
        });
    }
}
