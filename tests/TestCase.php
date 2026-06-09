<?php

namespace Modules\StratosCore\Tests;

use Modules\StratosCore\Providers\AppServiceProvider;
use Modules\StratosCore\Providers\RouteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        // Module migrations are loaded by AppServiceProvider once it's wired up.
    }

    protected function getPackageProviders($app): array
    {
        return [
            AppServiceProvider::class,
            RouteServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
