<?php
use \Modules\StratosCore\Http\Controllers\Api\DataController;
use \Modules\StratosCore\Http\Controllers\Api\FlightsController;
use \Modules\StratosCore\Http\Controllers\Api\PilotController;
use \Modules\StratosCore\Http\Middleware\StratosAuth;
use \Modules\StratosCore\Http\Middleware\StratosHeaders;

/**
 * Stratos ACARS API Routes
 * All endpoints use snake_case per the Stratos VA API specification.
 */

Route::group(['middleware' => [StratosHeaders::class]], function () {
    // Public endpoints (no auth required)
    Route::match(['get', 'options'], '/', function () {
        // Resolve module.json relative to this file so the route works under
        // both real phpVMS (where base_path('modules/...') maps to disk) and
        // Orchestra Testbench (where base_path points at a stub Laravel app).
        $moduleJson = \Illuminate\Support\Facades\File::get(dirname(__DIR__, 2) . '/module.json');
        $moduleData = json_decode($moduleJson, true);
        $version = $moduleData['version'] ?? 'unknown';
        return response()->json([
            "api_version" => $version,
            "handler" => "Stratos",
        ]);
    });

    Route::match(['post', 'options'], '/pilot/login', [PilotController::class, 'login']);

    // Authenticated endpoints
    Route::group(['middleware' => [StratosAuth::class]], function () {
        // Pilot
        Route::match(['get', 'options'], '/pilot/verify', [PilotController::class, 'verify']);
        Route::match(['get', 'options'], '/pilot/statistics', [PilotController::class, 'statistics']);

        // Data
        Route::group(['prefix' => '/data', 'controller' => DataController::class], function () {
            Route::match(['get', 'options'], '/aircraft', 'aircraft');
            Route::match(['get', 'options'], '/airports', 'airports');
            Route::match(['get', 'options'], '/announcements', 'announcements');
        });

        // Flights
        Route::group(['prefix' => '/flights', 'controller' => FlightsController::class], function () {
            Route::match(['get', 'options'], '/bookings', 'bookings');
            Route::match(['post', 'options'], '/complete', 'complete');
            Route::match(['post', 'options'], '/cancel', 'cancel');
            Route::match(['post', 'options'], '/start', 'start');
            Route::match(['get', 'options'], '/search', 'search');
            Route::match(['post', 'options'], '/unbook', 'unbook');
            Route::match(['post', 'options'], '/update', 'update');
        });
    });
});
