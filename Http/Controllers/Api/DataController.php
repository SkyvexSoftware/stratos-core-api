<?php

namespace Modules\StratosCore\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Enums\FareType;
use App\Models\News;
use Illuminate\Http\Request;

/**
 * class DataController
 */
class DataController extends Controller
{
    /**
     * GET /data/aircraft
     * Stratos expects: id, code, name, registration, maximum_passengers, maximum_cargo, minimum_rank.
     *
     * maximum_passengers is summed from the subfleet's passenger fares.
     * maximum_cargo comes from the subfleet's cargo_capacity column.
     * minimum_rank is the lowest-hours rank the subfleet is gated to,
     * or '' if the subfleet has no rank restriction.
     */
    public function aircraft(Request $request)
    {
        $aircraft = Aircraft::with(['subfleet.fares', 'subfleet.ranks'])
            ->orderBy('name')
            ->get();

        $output = [];
        foreach ($aircraft as $item) {
            $subfleet = $item->subfleet;
            $fares = $subfleet ? $subfleet->fares : collect();

            $maxPax = (int) $fares->where('type', FareType::PASSENGER)->sum('capacity');
            $maxCargo = (int) ($subfleet->cargo_capacity ?? 0);

            $minRank = '';
            if ($subfleet && $subfleet->ranks->isNotEmpty()) {
                $minRank = (string) $subfleet->ranks->sortBy('hours')->first()->name;
            }

            $output[] = [
                'id' => $item->id,
                'code' => $item->icao,
                'name' => $item->name,
                'registration' => $item->registration,
                'maximum_passengers' => $maxPax,
                'maximum_cargo' => $maxCargo,
                'minimum_rank' => $minRank,
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
        $airports = Airport::get()->map(function ($apt) {
            return [
                'id' => $apt->id,
                'code' => $apt->icao,
                'name' => $apt->name,
                'latitude' => $apt->lat,
                'longitude' => $apt->lon,
            ];
        });

        return response()->json($airports);
    }

    /**
     * GET /data/announcements
     */
    public function announcements(Request $request)
    {
        $news = News::latest()->take(10)->get();
        $output = [];
        foreach ($news as $item) {
            $body = (string) $item->body;
            $output[] = [
                'id' => (string) $item->id,
                'title' => $item->subject,
                'subtitle' => mb_substr(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 200),
                'body' => $body,
                'link' => null,
                'image' => null,
                'publish_date' => $item->created_at->toDateString(),
            ];
        }

        return response()->json($output);
    }
}
