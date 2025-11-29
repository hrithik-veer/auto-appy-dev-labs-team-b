<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\LiftService;
use App\Models\Lift;
use App\Helpers\FloorHelper;
use Illuminate\Support\Facades\Redis;
use Predis\Command\Redis\HMSET;

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
        // 1. Fetch all lifts 
        $keys = Redis::keys('lift:*');

        if (empty($keys)) {
            return response()->json([]);
        }
        $formatted = [];

        foreach ($keys as $key) {
            // 2. read full hash from redis
            $data = Redis::HGETALL($key);

            // Skipped Corrupted Files
            if (empty($data)) {
                continue;
            }

            // 3. Decode next_stops JSON
            $queue = !empty($data["next_stops"]) ? json_decode($data["next_stops"], true) : [];

            //4. formatted Output
            $formatted[] = [
                'lift_id' => $data["liftId"][1],
                "floor" => FloorHelper::getFloorId($data["current_floor"]),
                "direction" => $data["direction"],
                "queue" => $queue
            ];
        }
        return response()->json($formatted);
    }


    public function resetData()
    {
        DB::table('lifts')->update([
            'current_floor' => 1,      // reset floor to default
            'direction' => 'IDLE',     // reset direction
            'next_stops' => json_encode([]),  // empty queue
        ]);

        $keys = Redis::keys("lift:*");

        foreach ($keys as $key) {
            Redis::hmset(
                $key,
                [
                    "current_floor" => 1,
                    "direction" => "IDLE",
                    "next_stops" => json_encode([]),
                    'updated_at' => now()->toDateTimeString(),
                ]
            );
        }
    }
}
