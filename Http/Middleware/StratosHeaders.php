<?php

namespace Modules\StratosCore\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Class StratosHeaders
 * @package Modules\StratosCore\Http\Middleware
 */
class StratosHeaders
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
        $method = $request->method();
        if ($method === 'OPTIONS' || $method === 'HEAD')
        {
            $response = response()->json(null);
        }
        else {
            $response = $next($request);
        }
        $response->withHeaders([
            'Content-type' => 'application/json',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS, HEAD',
            'Access-Control-Allow-Headers' => 'Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Stratos-Version, User-Agent',
            'Access-Control-Allow-Origin' => '*'
        ]);
        return $response;

    }
}

