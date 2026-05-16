<?php

namespace Modules\StratosCore\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Pirep;
use Illuminate\Support\Facades\Auth;

/**
 * Minimal stub implementations of /pireps/{latest,search,details} for v0.1.0.
 * The full historical-PIREP surface lives in skyvexsoftware/stratos-logbook-api.
 * These three endpoints exist in Core for the desktop client's legacy probe
 * paths and will be fleshed out in a follow-up version.
 */
class PirepController extends Controller
{
    public function latest()
    {
        return [];
    }

    public function search()
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }
        return Pirep::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'submit_date' => $p->created_at?->toDateTimeString(),
                'flight_time' => $p->flight_time,
                'distance' => $p->distance,
                'landing_rate' => $p->landing_rate,
                'fuel_used' => $p->fuel_used,
                'status' => (string) $p->state,
            ])
            ->all();
    }

    public function details()
    {
        return [
            'flight_log' => [],
            'flight_data' => [],
            'location_data' => [],
        ];
    }
}
