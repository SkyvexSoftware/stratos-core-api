<?php

namespace Modules\StratosCore\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Class StratosAuth
 * @package Modules\StratosCore\Http\Middleware
 */
class StratosAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        $model = User::where('api_key', $token)->first();
        if (!is_null($model))
        {
            Auth::setUser($model);
            $request->attributes->add(['pilotID' => $model->id]);
            return $next($request);
        }

        else
            return response()->json(['success' => false, 'error' => 'Invalid Token'], 401);
    }
}

