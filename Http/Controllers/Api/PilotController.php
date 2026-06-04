<?php

namespace Modules\StratosCore\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * class PilotController
 */
class PilotController extends Controller
{
    /**
     * Build the pilot session response (used by login and verify).
     * Stratos expects snake_case field names and 'token' instead of 'session'.
     */
    private function retrieveUserInformation($user, $includeToken = true)
    {
        $pilotIDSetting = setting('pilots_id_length', 4);

        $name = explode(' ', $user['name']);
        if (count($name) <= 1) {
            $first = $name[0];
            $last = '';
        } else {
            $first = $name[0];
            $last = $name[1];
        }

        $data = [
            'db_id' => $user['id'],
            'pilot_id' => $user['airline']['icao'].str_pad($user['pilot_id'], $pilotIDSetting, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'email' => $user['email'],
            'rank' => $user['rank']['name'],
            'rank_image' => $user->rank?->image_url,
            'rank_level' => $this->resolveRankLevel($user->rank),
            'avatar' => $user->resolveAvatarUrl(),
        ];

        if ($includeToken) {
            $data['token'] = $user['api_key'];
        }

        return $data;
    }

    /**
     * 0-based rank tier — count of ranks below this one on the hours ladder.
     * Matches phpVMS's auto-promote ordering (Rank::orderBy('hours', 'asc')).
     */
    private function resolveRankLevel(?Rank $rank): int
    {
        if ($rank === null) {
            return 0;
        }

        return Rank::where('hours', '<', $rank->hours)->count();
    }

    /**
     * POST /pilot/login
     * Stratos sends JSON body with username + password
     */
    public function login(Request $request)
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        $user = $this->resolveLoginUser($username);

        if ($user === null) {
            return response()->json(['success' => false, 'error' => 'The username or password is incorrect'], 401);
        }

        if (password_verify($password, $user['password']) || hash_equals((string) $user['api_key'], $password)) {
            return response()->json($this->retrieveUserInformation($user));
        }

        return response()->json(['success' => false, 'error' => 'The username or password is incorrect'], 401);
    }

    /**
     * Resolve the pilot for a login attempt. Stratos sends the displayed pilot ID
     * (e.g. "QFA0001"), but pilot_id is an integer column — strip the airline
     * prefix before querying.
     */
    private function resolveLoginUser(string $username): ?User
    {
        if ($username === '') {
            return null;
        }

        if (str_contains($username, '@')) {
            return User::with('airline', 'rank')->where('email', $username)->first();
        }

        $pilotId = (int) preg_replace('/\D/', '', $username);

        return $pilotId > 0
            ? User::with('airline', 'rank')->where('pilot_id', $pilotId)->first()
            : null;
    }

    /**
     * GET /pilot/verify
     * Validates the bearer token and returns pilot profile (no token in response)
     */
    public function verify(Request $request)
    {
        $user = Auth::user();
        $user->load('airline', 'rank');

        return response()->json($this->retrieveUserInformation($user, false));
    }

    /**
     * GET /pilot/statistics
     * Returns pilot career stats in snake_case
     */
    public function statistics(Request $request)
    {
        $user = User::where('id', Auth::user()->id)->with(['pireps', 'rank'])->first();

        return response()->json([
            'hours_flown' => round($user->flight_time / 60, 2),
            'flights_flown' => (string) $user->pireps->count(),
            'average_landing_rate' => round($user->pireps->avg('landing_rate') ?? 0, 2),
            'pireps_filed' => (string) $user->pireps->count(),
            'total_pay' => 0,
            'flight_streak' => 0,
            'location' => $user->curr_airport_id ?? '',
            'ff_level' => 0,
            'ff_status' => 0,
            'rank_image' => $user->rank?->image_url,
        ]);
    }
}
