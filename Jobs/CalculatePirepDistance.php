<?php

namespace Modules\StratosCore\Jobs;

use App\Models\Pirep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\StratosCore\Actions\PirepDistanceCalculation;

/**
 * Class CalculatePirepDistance
 */
class CalculatePirepDistance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Pirep $pirep)
    {
        //
    }

    public function handle()
    {
        $distance = PirepDistanceCalculation::calculatePirepDistance($this->pirep);
        $this->pirep->distance = $distance;
        $this->pirep->save();
    }
}
