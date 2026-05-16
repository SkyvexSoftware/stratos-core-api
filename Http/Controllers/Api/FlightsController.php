<?php

namespace Modules\StratosCore\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Acars;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\FareType;
use App\Models\Enums\FlightType;
use App\Models\Enums\PirepSource;
use App\Models\Enums\PirepFieldSource;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\PirepFare;
use App\Models\Subfleet;
use App\Models\User;
use App\Services\BidService;
use App\Services\FareService;
use App\Services\FlightService;
use App\Services\PirepService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\StratosCore\Actions\PirepDistanceCalculation;

/**
 * class FlightsController
 * @package Modules\StratosCore\Http\Controllers\Api
 */
class FlightsController extends Controller
{
    public function __construct(
        public FlightService $flightService,
        public FareService $fareService,
        public BidService $bidService,
        public PirepService $pirepService
    ) {
    }

    public function bookings(Request $request)
    {
        $user = Auth::user();
        $bids = $this->bidService->findBidsForUser($user);
        $bids->load(
            'flight',
            'flight.airline',
            'flight.simbrief',
            'flight.simbrief.aircraft',
            'flight.subfleets',
            'flight.subfleets.aircraft'
        );
        $output = [];

        foreach ($bids as $bid) {
            $aircraft = null;
            if ($bid->flight->simbrief) {
                $aircraft = $bid->flight->simbrief->aircraft->id;
            } elseif ($bid->aircraft_id !== null) {
                $aircraft = $bid->aircraft_id;
            } else {
                // Stratos expects a single integer aircraft ID; pick the first available
                foreach ($bid->flight->subfleets->sortBy('name') as $subfleet) {
                    foreach ($subfleet->aircraft->sortBy('registration') as $acf) {
                        if ($aircraft === null) {
                            $aircraft = $acf['id'];
                        }
                    }
                }
            }
            $ft_converted = floatval(number_format($bid->flight->flight_time / 60, 2));

            if (setting('pilots.only_flights_from_current') && $bid->flight->dpt_airport_id !== $user->curr_airport_id) {
                continue;
            }

            $output[] = [
                "bid_id" => $bid->id,
                "number" => $bid->flight->flight_number,
                "code" => $bid->flight->airline->code,
                "departure_airport" => $bid->flight->dpt_airport_id,
                "arrival_airport" => $bid->flight->arr_airport_id,
                "route" => $bid->flight->route ? explode(" ", $bid->flight->route) : [],
                "flight_level" => $bid->flight->level,
                "distance" => $bid->flight->distance->local(),
                "departure_time" => $bid->flight->dpt_time,
                "arrival_time" => $bid->flight->arr_time,
                "flight_time" => $ft_converted,
                "days_of_week" => $bid->flight->days ?? [],
                "type" => $this->flightType($bid->flight->flight_type),
                "aircraft" => $aircraft,
                "notes" => $bid->flight->notes ?? ''
            ];
        }

        return response()->json($output);
    }
    public function cancel(Request $request)
    {
        // Prefer explicit pirep id from the client; fall back to the
        // authed pilot's current in-progress PIREP if none was supplied.
        // The Stratos shell currently sends an empty body on cancel and
        // relies on this lookup — purely phpVMS-native (no bolt-on tables).
        $pirepId = $request->input('uuid') ?? $request->input('tracking_id');
        if (!empty($pirepId)) {
            $pirep = Pirep::find($pirepId);
        } else {
            $pirep = Pirep::where('user_id', Auth::id())
                ->where('state', PirepState::IN_PROGRESS)
                ->latest()
                ->first();
        }
        if (!$pirep) {
            return response()->json(['error' => 'No active PIREP to cancel'], 404);
        }
        $this->pirepService->cancel($pirep);
        return response()->json(['status' => 200]);
    }
    public function complete(Request $request)
    {
        $input = $request->all();
        $flightLog = $input['flight_log'] ?? [];
        $flightData = $input['flight_data'] ?? [];
        // The shell historically sends 'tracking_id' here and 'uuid' on
        // /flights/update — accept either name (synonyms — both are the
        // PIREP id we hand back from /flights/start).
        $pirepId = $input['uuid'] ?? $input['tracking_id'] ?? null;
        if (empty($pirepId)) {
            return response()->json(['error' => 'tracking_id is required'], 400);
        }
        $pirep = Pirep::find($pirepId);
        if (!$pirep) {
            return response()->json(['error' => 'PIREP not found'], 404);
        }

        $pirep->status = PirepStatus::ARRIVED;
        $pirep->state = PirepState::PENDING;
        $pirep->source = PirepSource::ACARS;
        $pirep->source_name = "Stratos ACARS";
        $pirep->landing_rate = $input['landing_rate'] ?? 0;
        $pirep->fuel_used = $input['fuel_used'] ?? 0;
        $flightTime = $input['flight_time'] ?? 0;
        $pirep->flight_time = $flightTime * 60;
        // Only overwrite route if the ACARS client actually sends one;
        // otherwise keep the route that was set during prefile from the flight schedule.
        if (isset($input['route']) && !empty($input['route'])) {
            $pirep->route = is_array($input['route']) ? join(" ", $input['route']) : $input['route'];
        }
        $pirep->submitted_at = Carbon::now('UTC');

        // Process log entries (array of {timestamp, event} objects).
        $logEntries = !empty($flightData) ? $flightData : $flightLog;

        foreach ($logEntries as $data) {
            $log_item = new Acars();
            $log_item->type = AcarsType::LOG;
            $log_item->log = $data['event'] ?? '';
            $ts = $data['timestamp'] ?? null;
            $log_item->created_at = $ts ? Carbon::parse($ts) : Carbon::now('UTC');
            $pirep->acars_logs()->save($log_item);

            $message = (string) ($data['event'] ?? '');

            if (str_contains($message, "Pushing back with")) {
                if (preg_match('/Pushing back with(?:\s+a)?\s+zero fuel weight of\s+([0-9,.]+)\s*([A-Za-z]+)\s+and\s+([0-9,.]+)\s*([A-Za-z]+)\s+of fuel/i', $message, $matches)) {
                    $zfw_amount = (float) str_replace(',', '', $matches[1]);
                    $zfw_units = strtolower($matches[2]);
                    $fuel_amount = (float) str_replace(',', '', $matches[3]);
                    $fuel_units = strtolower($matches[4]);

                    if (in_array($zfw_units, ['kg', 'kgs'], true)) {
                        $zfw_amount = $zfw_amount * 2.20462;
                    }
                    if (in_array($fuel_units, ['kg', 'kgs'], true)) {
                        $fuel_amount = $fuel_amount * 2.20462;
                    }

                    $pirep->zfw = $zfw_amount;
                    $pirep->block_fuel = $fuel_amount;
                    continue;
                }

                if (preg_match('/Pushing back with\s+([0-9,.]+)\s*([A-Za-z]+)\s+of fuel/i', $message, $matches)) {
                    $fuel_amount = (float) str_replace(',', '', $matches[1]);
                    $fuel_units = strtolower($matches[2]);
                    if (in_array($fuel_units, ['kg', 'kgs'], true)) {
                        $fuel_amount = $fuel_amount * 2.20462;
                    }
                    $pirep->block_fuel = $fuel_amount;
                }
            }
        }

        $this->pirepService->updateCustomFields($pirep->id, [
            [
                'name' => 'Filed by',
                'value' => 'Stratos ACARS',
                'source' => PirepFieldSource::ACARS,
            ],
        ]);
        $comments = $input['comments'] ?? null;
        if (!empty($comments) && is_array($comments)) {
            // Stratos sends comments as a dedicated array of strings
            foreach ($comments as $comment) {
                $commentText = is_array($comment) ? ($comment['event'] ?? $comment['text'] ?? $comment['comment'] ?? json_encode($comment)) : (string) $comment;
                if (!empty($commentText)) {
                    $pirep->comments()->create([
                        'user_id' => Auth::user()->id,
                        'comment' => $commentText
                    ]);
                }
            }
        }
        // Distance is recalculated from phpVMS's native acars FLIGHT_PATH
        // rows (written by /flights/update during the flight). No need to
        // store a separate copy of the client-side history blob.
        $pirep->distance = PirepDistanceCalculation::calculatePirepDistance($pirep);
        $pirep->save();

        $this->pirepService->submit($pirep);

        // Reload relationships so the response includes useful metadata.
        $pirep->load(['airline', 'aircraft', 'dpt_airport', 'arr_airport']);

        return response()->json([
            'pirep_id' => $pirep->id,
            'flight_number' => optional($pirep->airline)->code . $pirep->flight_number,
            'route' => $pirep->dpt_airport_id . ' - ' . $pirep->arr_airport_id,
            'aircraft' => optional($pirep->aircraft)->name ?? optional($pirep->aircraft)->registration ?? '',
            'aircraft_icao' => optional($pirep->aircraft)->icao ?? '',
            'registration' => optional($pirep->aircraft)->registration ?? '',
            'airline' => optional($pirep->airline)->name ?? '',
            'airline_icao' => optional($pirep->airline)->icao ?? '',
        ]);
    }
    public function search(Request $request)
    {
        $output = [];
        $query = [];
        $subfleet = null;
        $limit = 100;

        if ($request->has('limit') && $request->query('limit') !== null) {
            $limit = min($request->query('limit'), 100);
        }
        $depApt = $request->query('departure_airport');
        if ($depApt !== null) {
            $apt = Airport::where('icao', $depApt)->first();
            if (!is_null($apt)) {
                $query['dpt_airport_id'] = $apt->id;
            }
        }
        if (setting('pilots.only_flights_from_current')) {
            $query['dpt_airport_id'] = $request->user()->curr_airport_id;
        }
        $arrApt = $request->query('arrival_airport');
        if ($arrApt !== null) {
            $apt = Airport::where('icao', $arrApt)->first();
            if (!is_null($apt)) {
                $query['arr_airport_id'] = $apt->id;
            }
        }
        if ($request->has('aircraft') && $request->query('aircraft') !== null) {
            $apt = Subfleet::find($request->query('aircraft'));
            if (!is_null($apt)) {
                $subfleet = $apt->id;
            }
        }

        // Build the base query builder
        $flightQuery = Flight::with('subfleets', 'subfleets.aircraft', 'airline');
        if (!empty($query)) {
            $flightQuery->where($query);
        }
        if (!empty($subfleet)) {
            $flightQuery->whereHas('subfleets', function ($q) use ($subfleet) {
                $q->where(['subfleets.id' => $subfleet, 'visible' => true]);
            });
        } else {
            $flightQuery->where('visible', true);
        }

        // Stratos filter: minimum_flight_time / maximum_flight_time (decimal hours → DB stores minutes)
        $minFt = $request->query('minimum_flight_time');
        if ($minFt !== null) {
            $flightQuery->where('flight_time', '>=', floatval($minFt) * 60);
        }
        $maxFt = $request->query('maximum_flight_time');
        if ($maxFt !== null) {
            $flightQuery->where('flight_time', '<=', floatval($maxFt) * 60);
        }

        // Stratos filter: minimum_distance / maximum_distance (nautical miles)
        $minDist = $request->query('minimum_distance');
        if ($minDist !== null) {
            $flightQuery->where('distance', '>=', floatval($minDist));
        }
        $maxDist = $request->query('maximum_distance');
        if ($maxDist !== null) {
            $flightQuery->where('distance', '<=', floatval($maxDist));
        }

        $flights = $flightQuery->take($limit)->get();

        foreach ($flights as $flight) {
            $aircraftName = null;
            $flight = $this->flightService->filterSubfleets($request->user(), $flight);
            foreach ($flight->subfleets as $subfleet) {
                foreach ($subfleet->aircraft as $acf) {
                    if ($aircraftName === null) {
                        $aircraftName = $acf['name'];
                    }
                }
            }
            $ft_converted = floatval(number_format($flight->flight_time / 60, 2));
            $output[] = [
                "id" => $flight->id,
                "number" => $flight->flight_number,
                "code" => $flight->airline->code,
                "departure_airport" => $flight->dpt_airport_id,
                "arrival_airport" => $flight->arr_airport_id,
                "flight_level" => $flight->level,
                "route" => $flight->route ?? null,
                "distance" => $flight->distance->local(),
                "departure_time" => $flight->dpt_time,
                "arrival_time" => $flight->arr_time,
                "flight_time" => $ft_converted,
                "days_of_week" => [],
                "type" => $this->flightType($flight->flight_type),
                "aircraft" => $aircraftName,
                "notes" => $flight->notes
            ];
        }

        return response()->json($output);
    }
    public function start(Request $request)
    {
        $user = Auth::user();
        $bidId = $request->input('bid_id');
        // Stratos may send an empty body; fall back to user's latest bid
        if ($bidId) {
            $bid = Bid::find($bidId);
        } else {
            $bid = Bid::where('user_id', $user->id)->latest()->first();
        }
        logger($request->all());
        $flight = Flight::find($bid->flight_id);
        $aircraft = null;
        if ($bid->flight->simbrief) {
            $aircraft = $bid->flight->simbrief->aircraft->id;
        } elseif ($bid->aircraft_id !== null) {
            $aircraft = $bid->aircraft_id;
        } else {
            return response()->json(['message' => 'No aircraft attached to bid'], 500);
        }

        // Prefer the SimBrief route string (waypoints) over the flight's route field,
        // because many flights have an empty route column while SimBrief always has one.
        $simbrief = $flight->simbrief()->where('user_id', $user->id)->first();
        $route = $flight->route;
        if ($simbrief !== null && empty($route)) {
            try {
                $route = $simbrief->xml->getRouteString();
            } catch (\Throwable $e) {
                Log::warning('Could not read route from SimBrief XML: ' . $e->getMessage());
            }
        }

        $attrs = [
            'flight_number' => $flight->flight_number,
            'airline_id' => $flight->airline_id,
            'route_code' => $flight->route_code,
            'route_leg' => $flight->route_leg,
            'flight_type' => $flight->flight_type,
            'dpt_airport_id' => $flight->dpt_airport_id,
            'arr_airport_id' => $flight->arr_airport_id,
            'route' => $route,
            'planned_distance' => $flight->distance,
            'aircraft_id' => $aircraft,
            'flight_id' => $flight->id,
            'source' => PirepSource::ACARS,
            'source_name' => "Stratos"
        ];
        if ($simbrief !== null) {
            $attrs['simbrief_id'] = $simbrief->id;
        }

        try {
            $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
            $this->generateFares(Aircraft::find($aircraft), $flight, $pirep);
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['message' => $e->getMessage()], 500);
        }
        Log::debug("Sending tracking_id: " . $pirep->id);
        return response()->json(['tracking_id' => $pirep->id]);
    }
    public function unbook(Request $request)
    {
        $bid = Bid::where(['user_id' => Auth::user()->id, 'id' => $request->post('bid_id')])->first();
        $flight = Flight::find($bid->flight_id);
        $this->bidService->removeBid($flight, Auth::user());
        return response()->json(['status' => 200]);
    }
    public function update(Request $request)
    {
        $input = $request->all();
        // The shell sends 'uuid' here and 'tracking_id' on /flights/complete —
        // accept either name (synonyms — both are the PIREP id returned by start).
        $pirepId = $input['uuid'] ?? $input['tracking_id'] ?? null;
        if (empty($pirepId)) {
            return response()->json(['error' => 'tracking_id is required'], 400);
        }
        $pirep = Pirep::find($pirepId);
        if ($pirep === null) {
            return response()->json(['error' => 'PIREP not found'], 404);
        }

        $pirep->status = $this->phaseToStatus($input['phase']);
        if (($pirep->status == PirepStatus::TAKEOFF || $pirep->status == PirepStatus::INIT_CLIM || $pirep->status == PirepStatus::ENROUTE) && $pirep->block_off_time == null) {
            $pirep->block_off_time = Carbon::now();
        }
        if (($pirep->status == PirepStatus::LANDED || $pirep->status == PirepStatus::ARRIVED) && $pirep->block_on_time == null) {
            $pirep->block_on_time = Carbon::now();
        }
        $pirep->updated_at = Carbon::now();
        $first_acars = $pirep->acars()->first();
        if ($first_acars !== null) {
            $minutes = Carbon::now()->diffInMinutes($first_acars->created_at);
            $pirep->flight_time = $minutes;
        }
        $pirep->save();
        $pirep->acars()->create([
            'status' => $pirep->status,
            'type' => AcarsType::FLIGHT_PATH,
            'lat' => $input['latitude'],
            'lon' => $input['longitude'],
            'distance' => $pirep->planned_distance->local(2) - ($input['distance_remaining'] ?? 0),
            'heading' => $input['heading'],
            'altitude' => $input['altitude'],
            'gs' => $input['ground_speed'] ?? 0
        ]);
    }

    public function phaseToStatus(string $phase)
    {
        switch (strtolower($phase)) {
            case 'boarding':
                return PirepStatus::BOARDING;
            case 'push_back':
                return PirepStatus::PUSHBACK_TOW;
            case 'taxi':
                return PirepStatus::TAXI;
            case 'take_off':
                return PirepStatus::TAKEOFF;
            case 'rejected_take_off':
                return PirepStatus::TAXI;
            case 'climb_out':
                return PirepStatus::INIT_CLIM;
            case 'climb':
                return PirepStatus::ENROUTE;
            case 'cruise':
                return PirepStatus::ENROUTE;
            case 'descent':
                return PirepStatus::APPROACH;
            case 'approach':
                return PirepStatus::APPROACH_ICAO;
            case 'final':
                return PirepStatus::LANDING;
            case 'landed':
                return PirepStatus::LANDED;
            case 'go_around':
                return PirepStatus::APPROACH;
            case 'taxi_to_gate':
            case 'taxi_in':
                return PirepStatus::LANDED;
            case 'arrived':
            case 'deboarding':
                return PirepStatus::ARRIVED;
            case 'diverted':
                return PirepStatus::DIVERTED;
            default:
                return PirepStatus::ENROUTE;
        }
    }
    private function generateFares($aircraft, $flight, $pirep)
    {
        $all_fares = $this->fareService->getFareWithOverrides($aircraft->subfleet->fares, $flight->fares);

        if ($flight->flight_type === FlightType::CHARTER_PAX_ONLY) {
            $bag_weight = setting('simbrief.charter_baggage_weight', 28);
        } else {
            $bag_weight = setting('simbrief.noncharter_baggage_weight', 35);
        }

        $lfactor = $flight->load_factor ?? setting('flights.default_load_factor');
        $lfactorv = $flight->load_factor_variance ?? setting('flights.load_factor_variance');
        $loadmin = max($lfactor - $lfactorv, 0);
        $loadmax = min($lfactor + $lfactorv, 100);
        if ($loadmax === 0) {
            $loadmax = 100;
        }

        if (setting('flights.use_cargo_load_factor ', false)) {
            $cgolfactor = $flight->load_factor ?? setting('flights.default_cargo_load_factor');
            $cgolfactorv = $flight->load_factor_variance ?? setting('flights.cargo_load_factor_variance');
            $cgoloadmin = max($cgolfactor - $cgolfactorv, 0);
            $cgoloadmax = min($cgolfactor + $cgolfactorv, 100);
            if ($cgoloadmax === 0) {
                $cgoloadmax = 100;
            }
        } else {
            $cgoloadmin = $loadmin;
            $cgoloadmax = $loadmax;
        }

        $pax_load_sheet = [];
        $tpaxfig = 0;
        $fares = [];
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::PASSENGER || empty($fare->capacity)) {
                continue;
            }
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count' => floor(($fare->capacity * rand($loadmin, $loadmax)) / 100)
            ]);
        }

        if (setting('units.weight') === 'kg') {
            $tbagload = round(($bag_weight * $tpaxfig) / 2.205);
        } else {
            $tbagload = round($bag_weight * $tpaxfig);
        }
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::CARGO || empty($fare->capacity)) {
                continue;
            }
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count' => ceil((($fare->capacity - $tbagload) * rand($cgoloadmin, $cgoloadmax)) / 100)
            ]);
        }
        $this->fareService->saveToPirep($pirep, $fares);
    }
    public function flightType($type)
    {
        switch ($type) {
            case 'J':
            case 'E':
            case 'C':
            case 'G':
            case 'O':
                return 'P';
            case 'A':
            case 'H':
            case 'I':
            case 'K':
            case 'M':
            case 'P':
            case 'T':
            case 'W':
            case 'X':
                return 'C';
        }
    }
}
