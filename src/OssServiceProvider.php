<?php

namespace Maxlcoder\LaravelOss;

use Illuminate\Support\ServiceProvider;

class OssServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // 发布配置
        $this->publishes([__DIR__.'/config/oss.php' => config_path('oss.php')], 'config');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('oss', function ($app) {
            return new Oss();
        });
    }

}