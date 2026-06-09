<?php

namespace Modules\StratosCore\Providers;

use App\Contracts\Modules\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private $moduleSvc;

    protected $defer = false;

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->moduleSvc = app('App\Services\ModuleService');

        $this->registerTranslations();
        $this->registerConfig();

        // Load migrations for the skyvexsoftware_active_flights and skyvexsoftware_pirep_logs tables
        $this->loadMigrationsFrom(__DIR__.'/../Database/migrations');
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        //
    }

    /**
     * Add module links here
     */
    public function registerLinks(): void {}

    /**
     * Register config.
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('stratoscore.php'),
        ], 'stratoscore');

        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'stratoscore');
    }

    /**
     * Register translations.
     */
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/stratoscore');

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, 'stratoscore');
        } else {
            $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'stratoscore');
        }
    }
}
