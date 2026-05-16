<?php

namespace Modules\StratosCore\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\News;
use Illuminate\Http\Request;

/**
 * class DataController
 * @package Modules\StratosCore\Http\Controllers\Api
 */
class DataController extends Controller
{
    /**
     * GET /data/aircraft
     * Stratos expects: id, code, name, service_ceiling, registration, maximum_passengers, maximum_cargo, minimum_rank
     */
    public function aircraft(Request $request)
    {
        $aircraft = Aircraft::orderBy('name')->get();
        $output = [];
        foreach ($aircraft as $item) {
            $output[] = [
                "id"                 => $item->id,
                "code"               => $item->icao,
                "name"               => $item->name,
                "service_ceiling"    => 41000,
                "registration"       => $item->registration,
                "maximum_passengers" => 300,
                "maximum_cargo"      => 1000,
                "minimum_rank"       => "",
            ];
        }
        return response()->json($output);
    }

    /**
     * GET /data/airports
     * Snake-case response shape, one entry per airport.
     */
    public function airports(Request $request)
    {
        $airports = Airport::get()->map(function($apt) {
            return [
                'id'        => $apt->id,
                'code'      => $apt->icao,
                'name'      => $apt->name,
                'latitude'  => $apt->lat,
                'longitude' => $apt->lon
            ];
        });
        return response()->json($airports);
    }

    /**
     * GET /data/announcements
     * Stratos expects an array of announcements with id, title, subtitle, link, image, publish_date
     */
    public function announcements(Request $request)
    {
        $news = News::latest()->take(10)->get();
        $output = [];
        foreach ($news as $item) {
            $output[] = [
                'id'           => (string) $item->id,
                'title'        => $item->subject,
                'subtitle'     => strip_tags(substr($item->body, 0, 200)),
                'link'         => null,
                'image'        => null,
                'publish_date' => $item->created_at->toDateString(),
            ];
        }
        return response()->json($output);
    }
}

