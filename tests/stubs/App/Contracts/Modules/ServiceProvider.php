<?php

namespace App\Contracts\Modules;

/**
 * Test-only stub of phpVMS's module ServiceProvider base class.
 * Provides just enough surface for Orchestra Testbench to autoload our
 * AppServiceProvider without pulling in the full phpVMS app.
 * The real class lives in `app/Contracts/Modules/ServiceProvider.php`
 * inside phpVMS — see https://github.com/nabeelio/phpvms.
 */
abstract class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot(): void {}
}
