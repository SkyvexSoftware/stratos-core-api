<?php

namespace Modules\StratosCore\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * Register the routes required for the Stratos module
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * The root namespace to assume when generating URLs to actions.
     *
     * @var string
     */
    protected $namespace = 'Modules\StratosCore\Http\Controllers';

    /**
     * Called before routes are registered.
     *
     * @return void
     */
    public function before(Router $router)
    {
        //
    }

    /**
     * Define the routes for the application.
     *
     *
     * @return void
     */
    public function map(Router $router)
    {
        $this->registerApiRoutes();
    }

    /**
     * Register the Stratos API routes under /api/stratos
     */
    protected function registerApiRoutes(): void
    {
        $config = [
            'as' => 'api.stratoscore.',
            'prefix' => 'api/stratos',
            'namespace' => $this->namespace.'\Api',
            'middleware' => ['api'],
        ];

        Route::group($config, function () {
            $this->loadRoutesFrom(__DIR__.'/../Http/Routes/api.php');
        });
    }
}
