<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Services\LiftService;
use App\Models\Lift;
use App\Helpers\FloorHelper;
use Illuminate\Support\Facades\Redis;
use Predis\Command\Redis\HMSET;





/**
    * CallLiftController
    * ------------------------------------------------------------
    * This controller handles all API operations related to lift requests,
    * real-time lift state retrieval, and communication between frontend
    * (React) and backend lift logic (LiftService + Redis).
    *
    * Responsibilities:
    *  - Receive API requests from frontend
    *  - Validate incoming data
    *  - Call appropriate  methods (LiftService)
    *  - Return clean JSON responses
    *
    * This controller SHOULD NOT contain business logic. All logic is handled
    * inside LiftService or CacheHelper classes.
    */
class CallLiftController extends Controller
{
    protected $liftService;

    /**
     * Constructor
     *
     * Injects the LiftService to handle core lift assignment logic.
     *
     * @param LiftService $service
     */

    public function __construct(LiftService $liftService)
    {
        $this->liftService = $liftService;
    }





    /**
     * API: Request a Lift
     * -------------------------------
     * Endpoint: POST /api/request-lift
     *
     * Purpose:
     *  Handles a user's lift request. The controller validates the input
     *  and then delegates the assignment logic to LiftService. The backend
     *  returns the assigned lift along with its updated state.
     *
     * Request Body:
     * ```
     * {
     *     "floor": 5,
     *     "direction": "UP"
     * }
     * ```
     *
     * Valid Directions:
     *  - UP
     *  - DOWN
     *
     * Response Example:
     * ```
     * {
     *   "success": true,
     *   "message": "Lift assigned successfully",
     *   "lift": {
     *       "lift_id": "L2",
     *       "current_floor": 3,
     *       "direction": "UP",
     *       "next_stops": [5, 7]
     *   }
     * }
     * ```
     *
     * @param Request $request
     * @return JsonResponse
     */


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


    /**
     * API: Add the Destination/traget floor Where to go.
     * -----------------------------------
     * Endpoint: POST /api/lifts/{liftId}
     *
     * Purpose:
     * 
     *  This API is typically called repeatedly by the frontend (React)
     *  This endpoint is used when a passenger enters a lift and selects a destination floor.
     *
     * 
     * ```
     *  The backend:
     *
     *   Fetches the current lift state from Redis
     *   Adds the destination floor to the lift's next_stops queue
     *   Updates Redis with the new queue
     *  
     * Response :
     * 
     * Lift Moved
     * 
     */


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





    /**
     * API: Cancel the Destination/traget floor Where to go.
     * -----------------------------------
     * Endpoint: POST /api/lifts/{liftId}/cancelled
     *
     * Purpose:
     * 
     *  Removes a requested floor from the lift's queue before the lift arrives.
     *     
     * 
     */

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




    /**
     * API: Get All Lift States
     * -----------------------------------
     * Endpoint: GET /api/lifts
     *
     * Purpose:
     *  Returns the real-time state of all lifts from Redis cache.  
     *  This API is typically called repeatedly by the frontend (React)
     *  using useEffect to show dynamic lift movement.
     *
     * Response Example:
     * ```
     * [
     *   {
     *     "lift_id": "L1",
     *     "current_floor": 4,
     *     "direction": "DOWN",
     *     "next_stops": [1]
     *   },
     *   {
     *     "lift_id": "L2",
     *     "current_floor": 7,
     *     "direction": "IDLE",
     *     "next_stops": []
     *   }
     * ]
     * ```
     *
     * @return JsonResponse
     */


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



    /**
     * API: Reset All Lift States
     * -----------------------------------
     * Endpoint: Put /api/lifts
     * 
     * 
     * Resets all lift data, deletes Redis keys, and reinitializes default lift states.
     * 
     * Request Body
     *
     * ---No request body required.
     * 
     * Response Body
     * 
     * {
     *  "lift_id": "L1",
     *  "current_floor": 0,
     *  "direction": "IDLE",
     *  "next_stops": []
     *  },
     *  {
     *  "lift_id": "L2",
     *  "current_floor": 0,
     *  "direction": "IDLE",
     *  "next_stops": []
     *  }
     *  
     */


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
