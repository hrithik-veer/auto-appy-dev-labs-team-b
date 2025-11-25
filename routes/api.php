<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CallLiftController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::controller(CallLiftController::class)->group(function(){
    Route::post('/lifts', 'request');
    Route::post('/lifts/{lift_id}', 'addDestination');
    Route::post('/lifts/{lift_id}/cancel' ,'cancelStop');
    Route::get('/alllifts', 'getAllLifts');
    Route::put('/lifts/reset','resetData');
});
