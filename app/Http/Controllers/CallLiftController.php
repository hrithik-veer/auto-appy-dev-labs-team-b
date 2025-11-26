<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\LiftService;
use App\Models\Lift;
use App\Helpers\FloorHelper;

class CallLiftController extends Controller
{
    protected $liftService;

    public function __construct(LiftService $liftService)
    {
        $this->liftService = $liftService;
    }

    public function request(Request $request)
    {
        $request->validate([
            'current_floor' => 'required|string',
            'direction' => 'required'
        ]);

        $lift = $this->liftService->requestLift(
            floor: $request->current_floor,
            direction: $request->direction
        );

        return $lift;
    }



    public function addDestination(Request $request, $liftId)
    {
        $request->validate([
            'destinations'   => 'required|array',
            'destinations.*' => 'string'
        ]);

        $destination = $this->liftService->addDestination(
            destinations: $request->destinations,
            liftId: $liftId
        );
        return "lift moved";
    }
    public function cancelStop(Request $request, $lift_id)
    {
        $request->validate([
            'destinations' => 'required|array',
            'destinations.*' => 'string'
        ]);

        $this->liftService->cancelLift(
            floors: $request->destinations,
            liftId: $lift_id
        );
    }

    public function getAllLifts()
    {
        $lifts = Lift::all();

        $formatted = $lifts->map(function ($lift) {

            $queue = is_array($lift->stops)
                ? $lift->stops
                : json_decode($lift->stops ?? "[]", true);
            return [
                "liftId"     => $lift->id,
                "floor"      => FloorHelper::getFloorId($lift->current_floor),
                "direction"  => $lift->direction,
                "queue"      => $queue // if stored as JSON
            ];
        });

        return response()->json($formatted);
    }


    public function resetData()
    {
        DB::table('lifts')->update([
            'current_floor' => 1,      // reset floor to default
            'direction' => 'IDLE',     // reset direction
            'next_stops' => json_encode([]),  // empty queue
        ]);

        return "Data Successfully Reset";
    }
}

















































   