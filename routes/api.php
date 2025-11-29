<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CallLiftController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | This file defines all API endpoints exposed by the application.
    | Each route maps an HTTP request to a controller method.
    | These routes handle lift operations, user requests, reset actions,
    | cancellations, and data fetching.
    |
    */

    /**
     * POST /api/lifts
     * Assigned the Best lift of requested people:
     * 
     *   
     * POST /api/lifts/{liftId}
     * Add the Destination of assigned lift.
     * 
     * 
     * POST /api/lifts/{liftId}/cancel
     * Cancels a user's previously created lift request.
     * 
     * 
     * POST /api/lifts/reset
     * Resets all lifts to default state.
     * 
     * 
     * GET /api/lifts
     * Fetches the live status of all lifts including:
     * 
     * 
     * @return JSON
     */

    Route::controller(CallLiftController::class)->group(function(){
        Route::post('/lifts', 'request');
        Route::post('/lifts/{lift_id}', 'addDestination');
        Route::post('/lifts/{lift_id}/cancel' ,'cancelStop');
        Route::get('/alllifts', 'getAllLifts');
        Route::put('/lifts/reset','resetData');
    });


