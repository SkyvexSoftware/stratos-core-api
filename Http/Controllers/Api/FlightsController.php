<?php

namespace Modules\StratosCore\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Events\PirepUpdated;
use App\Models\Acars;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\FareType;
use App\Models\Enums\FlightType;
use App\Models\Enums\PirepFieldSource;
use App\Models\Enums\PirepSource;
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
use Modules\StratosCore\Actions\EligibleAircraftList;
use Modules\StratosCore\Actions\PirepDistanceCalculation;

/**
 * class FlightsController
 */
class FlightsController extends Controller
{
    public function __construct(
        public FlightService $flightService,
        public FareService $fareService,
        public BidService $bidService,
        public PirepService $pirepService
    ) {}

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
            if (setting('pilots.only_flights_from_current') && $bid->flight->dpt_airport_id !== $user->curr_airport_id) {
                continue;
            }
            $output[] = $this->bookingPayload($bid);
        }

        return response()->json($output);
    }

    /**
     * Serialise one bid into the Stratos booking shape. Shared by /flights/bookings
     * and /flights/change-aircraft so both return identical payloads. Requires
     * flight, flight.airline, flight.simbrief(.aircraft) and flight.subfleets.aircraft
     * to be loaded on $bid.
     */
    private function bookingPayload(Bid $bid)
    {
        $aircraft = null;
        if ($bid->flight->simbrief) {
            $aircraft = $bid->flight->simbrief->aircraft->id;
        } elseif ($bid->aircraft_id !== null) {
            $aircraft = $bid->aircraft_id;
        }
        // When neither SimBrief nor the bid pins an aircraft, leave it null so the
        // client shows "no aircraft selected" instead of a misleading first-subfleet
        // default — and stays consistent with /flights/start, which has no fallback.
        $ft_converted = floatval(number_format($bid->flight->flight_time / 60, 2));

        return [
            'bid_id' => $bid->id,
            'number' => $bid->flight->flight_number,
            'code' => $bid->flight->airline->code,
            'departure_airport' => $bid->flight->dpt_airport_id,
            'arrival_airport' => $bid->flight->arr_airport_id,
            'route' => $bid->flight->route ? explode(' ', $bid->flight->route) : [],
            'flight_level' => $bid->flight->level,
            'distance' => $bid->flight->distance->local(),
            'departure_time' => $bid->flight->dpt_time,
            'arrival_time' => $bid->flight->arr_time,
            'flight_time' => $ft_converted,
            'days_of_week' => $bid->flight->days ?? [],
            'type' => $this->flightType($bid->flight->flight_type),
            'aircraft' => $aircraft,
            'aircraft_changeable' => ! $bid->flight->simbrief,
            'notes' => $bid->flight->notes ?? '',
        ];
    }

    /**
     * GET /flights/bookings/{bid}/aircraft
     * Aircraft the pilot may switch to for this bid's flight, narrowed by the
     * native FlightService::filterSubfleets (same logic phpVMS's own PIREP-create
     * form uses). A flight with a locked SimBrief airframe is not changeable.
     */
    public function eligibleAircraft(Request $request, $bid)
    {
        $user = Auth::user();
        $bidModel = Bid::with(['flight.simbrief.aircraft', 'flight.subfleets.aircraft'])->find($bid);

        if ($bidModel === null) {
            return response()->json(['error' => 'Bid not found'], 404);
        }
        if ((int) $bidModel->user_id !== (int) $user->id) {
            return response()->json(['error' => 'This bid does not belong to you'], 403);
        }
        if ($bidModel->flight->simbrief) {
            return response()->json(['changeable' => false, 'aircraft' => []]);
        }

        $flight = $this->flightService->filterSubfleets($user, $bidModel->flight);

        return response()->json([
            'changeable' => true,
            'aircraft' => EligibleAircraftList::shape($flight->subfleets),
        ]);
    }

    public function cancel(Request $request)
    {
        // Prefer explicit pirep id from the client; fall back to the
        // authed pilot's current in-progress PIREP if none was supplied.
        // The Stratos shell currently sends an empty body on cancel and
        // relies on this lookup — purely phpVMS-native (no bolt-on tables).
        $pirepId = $request->input('uuid') ?? $request->input('tracking_id');
        if (! empty($pirepId)) {
            $pirep = Pirep::find($pirepId);
        } else {
            $pirep = Pirep::where('user_id', Auth::id())
                ->where('state', PirepState::IN_PROGRESS)
                ->latest()
                ->first();
        }
        if (! $pirep) {
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
        // Shell sends either 'tracking_id' or 'uuid' — both are the PIREP id from /flights/start.
        $pirepId = $input['uuid'] ?? $input['tracking_id'] ?? null;
        if (empty($pirepId)) {
            return response()->json(['error' => 'tracking_id is required'], 400);
        }
        $pirep = Pirep::find($pirepId);
        if (! $pirep) {
            return response()->json(['error' => 'PIREP not found'], 404);
        }

        // Save log lines as Acars LOG rows. Format is client-customisable, so we don't parse them.
        $logEntries = ! empty($flightData) ? $flightData : $flightLog;
        foreach ($logEntries as $data) {
            $log_item = new Acars;
            $log_item->type = AcarsType::LOG;
            $log_item->log = $data['event'] ?? '';
            $ts = $data['timestamp'] ?? null;
            $log_item->created_at = $ts ? Carbon::parse($ts) : Carbon::now('UTC');
            $pirep->acars_logs()->save($log_item);
        }

        // Fuel/weight values are sent in lbs (phpVMS internal unit). Distance is recomputed
        // from FLIGHT_PATH rows written during /flights/update.
        $attrs = [
            'source' => PirepSource::ACARS,
            'source_name' => 'Stratos ACARS',
            'landing_rate' => $input['landing_rate'] ?? 0,
            'fuel_used' => $input['fuel_used'] ?? 0,
            'flight_time' => (int) (((float) ($input['flight_time'] ?? 0)) * 60),
            'distance' => PirepDistanceCalculation::calculatePirepDistance($pirep),
        ];

        foreach (['zfw', 'block_fuel', 'block_time', 'level'] as $optional) {
            if (isset($input[$optional]) && is_numeric($input[$optional])) {
                $attrs[$optional] = (float) $input[$optional];
            }
        }

        // Preserve the prefile route if the client doesn't send one.
        if (isset($input['route']) && ! empty($input['route'])) {
            $attrs['route'] = is_array($input['route']) ? implode(' ', $input['route']) : $input['route'];
        }

        $fields = [
            [
                'name' => 'Filed by',
                'value' => 'Stratos ACARS',
                'source' => PirepFieldSource::ACARS,
            ],
        ];

        // Pass through any custom PIREP fields the client supplies in a `fields`
        // map (e.g. landing-rate / landing-pitch / landing-roll). Defensive:
        // only runs when the client actually sends `fields`, so existing
        // clients are completely unaffected. Values are stringified; scalar
        // numerics stay numeric-parseable so maintenance modules like
        // DisposableSpecial (which gate on is_numeric() by slug) keep working —
        // send pure numbers without unit suffixes for those.
        if (isset($input['additional_fields']) && is_array($input['additional_fields'])) {
            foreach ($input['additional_fields'] as $name => $value) {
                if (is_array($value) || $value === null) {
                    continue;
                }
                $fields[] = [
                    'name'   => (string) $name,
                    'value'  => (string) $value,
                    'source' => PirepFieldSource::ACARS,
                ];
            }
        }

        try {
            $pirep = $this->pirepService->file($pirep, $attrs, $fields);
        } catch (\Throwable $e) {
            Log::error('Stratos /flights/complete file failed: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 500);
        }

        // file() doesn't handle comments — write them separately.
        $comments = $input['comments'] ?? null;
        if (! empty($comments) && is_array($comments)) {
            foreach ($comments as $comment) {
                $commentText = is_array($comment) ? ($comment['event'] ?? $comment['text'] ?? $comment['comment'] ?? json_encode($comment)) : (string) $comment;
                if (! empty($commentText)) {
                    $pirep->comments()->create([
                        'user_id' => Auth::user()->id,
                        'comment' => $commentText,
                    ]);
                }
            }
        }

        // submit() fires PirepFiled, handles diversion, and applies rank auto-approve.
        $this->pirepService->submit($pirep);

        $pirep->load(['airline', 'aircraft', 'dpt_airport', 'arr_airport']);

        return response()->json([
            'pirep_id' => $pirep->id,
            'flight_number' => optional($pirep->airline)->code.$pirep->flight_number,
            'route' => $pirep->dpt_airport_id.' - '.$pirep->arr_airport_id,
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
            if (! is_null($apt)) {
                $query['dpt_airport_id'] = $apt->id;
            }
        }
        if (setting('pilots.only_flights_from_current')) {
            $query['dpt_airport_id'] = $request->user()->curr_airport_id;
        }
        $arrApt = $request->query('arrival_airport');
        if ($arrApt !== null) {
            $apt = Airport::where('icao', $arrApt)->first();
            if (! is_null($apt)) {
                $query['arr_airport_id'] = $apt->id;
            }
        }
        if ($request->has('aircraft') && $request->query('aircraft') !== null) {
            $apt = Subfleet::find($request->query('aircraft'));
            if (! is_null($apt)) {
                $subfleet = $apt->id;
            }
        }

        // Build the base query builder
        $flightQuery = Flight::with('subfleets', 'subfleets.aircraft', 'airline');
        if (! empty($query)) {
            $flightQuery->where($query);
        }
        if (! empty($subfleet)) {
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
                'id' => $flight->id,
                'number' => $flight->flight_number,
                'code' => $flight->airline->code,
                'departure_airport' => $flight->dpt_airport_id,
                'arrival_airport' => $flight->arr_airport_id,
                'flight_level' => $flight->level,
                'route' => $flight->route ?? null,
                'distance' => $flight->distance->local(),
                'departure_time' => $flight->dpt_time,
                'arrival_time' => $flight->arr_time,
                'flight_time' => $ft_converted,
                'days_of_week' => [],
                'type' => $this->flightType($flight->flight_type),
                'aircraft' => $aircraftName,
                'notes' => $flight->notes,
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
        $simbrief = $flight->simbrief()->where('user_id', $user->id)->first();

        $aircraft = null;
        if ($simbrief !== null && $simbrief->aircraft_id !== null) {
            $aircraft = $simbrief->aircraft_id;
        } elseif ($bid->aircraft_id !== null) {
            $aircraft = $bid->aircraft_id;
        } else {
            return response()->json(['message' => 'No aircraft attached to bid'], 500);
        }

        // Prefer the SimBrief route string (waypoints) over the flight's route field,
        // because many flights have an empty route column while SimBrief always has one.
        $route = $flight->route;
        if ($simbrief !== null && empty($route)) {
            try {
                $route = $simbrief->xml->getRouteString();
            } catch (\Throwable $e) {
                Log::warning('Could not read route from SimBrief XML: '.$e->getMessage());
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
            'source_name' => 'Stratos',
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
        Log::debug('Sending tracking_id: '.$pirep->id);

        return response()->json(['tracking_id' => $pirep->id]);
    }

    public function unbook(Request $request)
    {
        $bid = Bid::where(['user_id' => Auth::user()->id, 'id' => $request->post('bid_id')])->first();
        $flight = Flight::find($bid->flight_id);
        $this->bidService->removeBid($flight, Auth::user());

        return response()->json(['status' => 200]);
    }

    /**
     * POST /flights/change-aircraft  { bid_id, aircraft_id }
     * Assigns a different aircraft to a bid before the flight is started, by
     * persisting the native Bid::aircraft_id column (/flights/start reads it).
     * Rejects simbrief-locked flights, started flights, aircraft outside the
     * flight's eligible set, and other pilots' bids.
     */
    public function changeAircraft(Request $request)
    {
        $user = Auth::user();
        $bidId = $request->input('bid_id');
        $aircraftId = $request->input('aircraft_id');

        if (empty($bidId) || empty($aircraftId)) {
            return response()->json(['message' => 'bid_id and aircraft_id are required'], 422);
        }

        $bid = Bid::with(['flight.simbrief.aircraft', 'flight.subfleets.aircraft'])->find($bidId);
        if ($bid === null) {
            return response()->json(['error' => 'Bid not found'], 404);
        }
        if ((int) $bid->user_id !== (int) $user->id) {
            return response()->json(['error' => 'This bid does not belong to you'], 403);
        }
        if ($bid->flight->simbrief) {
            return response()->json(['message' => 'Aircraft is fixed for this flight'], 422);
        }

        $inProgress = Pirep::where('user_id', $user->id)
            ->where('flight_id', $bid->flight_id)
            ->where('state', PirepState::IN_PROGRESS)
            ->exists();
        if ($inProgress) {
            return response()->json(['message' => 'Cannot change aircraft after the flight has started'], 409);
        }

        $flight = $this->flightService->filterSubfleets($user, $bid->flight);
        $eligibleIds = array_column(EligibleAircraftList::shape($flight->subfleets), 'id');
        if (! in_array((int) $aircraftId, $eligibleIds, true)) {
            return response()->json(['message' => "This aircraft isn't available for this flight"], 422);
        }

        $bid->aircraft_id = (int) $aircraftId;
        $bid->save();

        $bid->load(
            'flight',
            'flight.airline',
            'flight.simbrief',
            'flight.simbrief.aircraft',
            'flight.subfleets',
            'flight.subfleets.aircraft'
        );

        return response()->json($this->bookingPayload($bid));
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
        if ($pirep->state !== PirepState::IN_PROGRESS) {
            return response()->json(['error' => 'PIREP is no longer active', 'state' => $pirep->state, 'pirep_id' => $pirep->id], 409);
        }

        $pirep->status = $this->phaseToStatus($input['phase']);
        if (($pirep->status == PirepStatus::TAKEOFF || $pirep->status == PirepStatus::INIT_CLIM || $pirep->status == PirepStatus::ENROUTE) && $pirep->block_off_time == null) {
            $pirep->block_off_time = Carbon::now();
        }
                if ($pirep->status == PirepStatus::ON_BLOCK && $pirep->block_on_time == null) {
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
            // Native acars columns the phpVMS logbook Analytics tab plots but
            // that were dropped before. Defensive: only stored if the client
            // sends the optional field, otherwise null (no behaviour change).
            'altitude_agl' => $input['altitude_agl'] ?? null,
            'vs' => $input['vertical_speed'] ?? null,
            'gs' => $input['ground_speed'] ?? 0,
            'ias' => $input['indicated_airspeed'] ?? null,
        ]);

        event(new PirepUpdated($pirep));
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
                            return PirepStatus::TAXI;
            case 'on_block':
            case 'block_on':
            case 'at_gate':
            case 'deboarding':
                            return PirepStatus::ON_BLOCK;
            case 'arrived':
            case 'submitted':
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

        // Use ?: rather than ?? so a stored 0 (which the Dynamic Fares add-on
        // writes to flights to disable phpVMS's static fare math) falls through
        // to the VA-configured system default instead of skewing the roll.
        $lfactor = $flight->load_factor ?: setting('flights.default_load_factor');
        $lfactorv = $flight->load_factor_variance ?: setting('flights.load_factor_variance');
        $loadmin = max($lfactor - $lfactorv, 0);
        $loadmax = min($lfactor + $lfactorv, 100);

        if (setting('flights.use_cargo_load_factor ', false)) {
            $cgolfactor = $flight->load_factor ?: setting('flights.default_cargo_load_factor');
            $cgolfactorv = $flight->load_factor_variance ?: setting('flights.cargo_load_factor_variance');
            $cgoloadmin = max($cgolfactor - $cgolfactorv, 0);
            $cgoloadmax = min($cgolfactor + $cgolfactorv, 100);
        } else {
            $cgoloadmin = $loadmin;
            $cgoloadmax = $loadmax;
        }

        $tpaxfig = 0;
        $fares = [];
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::PASSENGER || empty($fare->capacity)) {
                continue;
            }
            $count = (int) floor(($fare->capacity * rand($loadmin, $loadmax)) / 100);
            $tpaxfig += $count;
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count' => $count,
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
                'count' => ceil((($fare->capacity - $tbagload) * rand($cgoloadmin, $cgoloadmax)) / 100),
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
