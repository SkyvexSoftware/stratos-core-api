<?php

namespace App\Services;

/**
 * Test-only stub of phpVMS's ModuleService.
 * Provides just enough surface for our AppServiceProvider's boot()
 * to resolve `app('App\Services\ModuleService')` under Orchestra Testbench
 * without pulling in the full phpVMS app.
 * The real class lives in `app/Services/ModuleService.php` inside phpVMS —
 * see https://github.com/nabeelio/phpvms.
 */
class ModuleService
{
    public function addLinks(...$args): void {}

    public function __call($name, $args)
    {
        // Swallow any other calls phpVMS might make on this service.
    }
}
