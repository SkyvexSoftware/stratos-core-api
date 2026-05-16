<?php

namespace Modules\StratosCore\Actions;

use App\Models\Enums\AcarsType;
use App\Models\Pirep;
use App\Services\GeoService;
use Illuminate\Support\Facades\Log;
use League\Geotools\Coordinate\Coordinate;
use League\Geotools\Geotools;

class PirepDistanceCalculation
{
    public static function calculatePirepDistance(Pirep $pirep): float
    {
        // Only use FLIGHT_PATH records for distance; LOG/ROUTE entries have no valid lat/lon
        $path_points = $pirep->acars()->where('type', AcarsType::FLIGHT_PATH)->get();

        $distance = 0;
        Log::debug("Stratos PathPoints:" . $path_points->count());
        if ($path_points->count() == 0) {
            $geotools = new Geotools();
            $start = new Coordinate([$pirep->dpt_airport->lat, $pirep->dpt_airport->lon]);
            $end = new Coordinate([$pirep->arr_airport->lat, $pirep->arr_airport->lon]);
            $dist = $geotools->distance()->setFrom($start)->setTo($end);

            return $dist->in(config('phpvms.internal_units.distance', 'nmi'))->greatCircle();
        }

        for ($i = 0; $i + 1 < $path_points->count(); $i++) {
            $from = $path_points[$i];
            $to = $path_points[$i + 1];

            $geotools = new Geotools();
            $start = new Coordinate([$from->lat, $from->lon]);
            $end = new Coordinate([$to->lat, $to->lon]);
            $dist = $geotools->distance()->setFrom($start)->setTo($end);
            $distance = $distance + $dist->greatCircle() / 1852;
        }

        Log::debug("Stratos Pirep Distance Calculation: " . $distance);
        return $distance;
    }
}
