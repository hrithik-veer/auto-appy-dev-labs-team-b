<?php

namespace App\Services;

use App\Models\Lift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Helpers\FloorHelper;
use Illuminate\Support\Facades\Cache;

use App\Services\LiftCaller;
use App\Helpers\StopSorter;



/**
 * Class LiftService
 *
 * This service handles all lift-related business logic such as:
 * - Fetching lift states from Redis
 * - Updating lift movements
 * - Managing next stops queues
 * - Assigning lifts to users
 *
 * Service classes are used to separate business logic from controllers
 * for better readability, testing, and maintainability.
 */



class LiftService
{
    private const MIN_FLOOR = 1;
    private const MAX_FLOOR = 17;

    /**
     * Start lift engine with proper locking to prevent multiple instances
     */
    private function startLiftEngineIfNotRunning()
    {
        // Use cache lock to prevent race conditions
        $lock = Cache::lock('lift_engine_start', 10);

        if ($lock->get()) {
            try {
                $running = shell_exec("pgrep -f 'php artisan lift:run'");

                if (!$running || trim($running) === '') {
                    shell_exec("php artisan lift:run > /dev/null 2>&1 &");
                }
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Constructor
     *
     * Injects the LiftService to handle core lift assignment logic.
     *
     * @param LiftService $service
     */


    protected $LiftCaller;

    public function __construct(LiftCaller $liftCaller)
    {
        $this->LiftCaller = $liftCaller;
    }




    /**
     * 
     *  
     * Assign a user request to the best lift.
     *
     * Logic:
     * - Check nearest lift
     * - Consider direction & movement
     * - Add destination floor to next_stops queue
     *
     * @param int $floor
     * @param string $direction (UP | DOWN)
     * @return array  Lift assignment details
     *
     */




    public function requestLift(string $floor, string $direction)
    {
        return $this->LiftCaller->HandletLiftrequest($floor, $direction);
    }


    

    /**
     * Add destination floors from inside the lift
     *  
     *
     *
     * Logic:
     * - Pass the lift Id into the url.
     * - Also pass the destination floor where to go.
     * - Add destination floor to next_stops queue
     *
     * @param int $liftId
     * @param string $destination floor 
     * @return array  destination floor added in next_stops[]
     *
     */




    public function addDestination(array $destinations, $liftId)
    {
        if (empty($destinations)) {
            return response()->json(['error' => 'No destinations provided'], 400);
        }

        // Redis key for this lift
        $liftKey = "lift:l{$liftId}";

        // Fetch lift from Redis
        $liftData = Redis::hgetall($liftKey);

        if (empty($liftData)) {
            return response()->json(['error' => 'Lift not found'], 404);
        }

        $current = isset($liftData['current_floor']) ? (int)$liftData['current_floor'] : 1;
        $stops = !empty($liftData['next_stops']) ? json_decode($liftData['next_stops'], true) : [];
        $liftDirection = $liftData['direction'] ?? 'IDLE';
        $validDestinationsAdded = false;

        foreach ($destinations as $floorString) {
            $destination = FloorHelper::getFloorNo($floorString);

            if ($destination === null) {
                return response()->json([
                    "error" => "Invalid floor: $floorString"
                ], 422);
            }

            if ($destination < self::MIN_FLOOR || $destination > self::MAX_FLOOR) {
                return response()->json([
                    "error" => "Floor out of range: $floorString"
                ], 422);
            }

            if ($destination === $current) {
                continue;
            }

            // Add to stops if not already present
            if (!in_array($destination, array_column($stops, 'floor'))) {
                $stops[] = [
                    "floor" => $destination,
                    "direction" => null
                ];
                $validDestinationsAdded = true;
            }
        }

        if (!$validDestinationsAdded) {
            return response()->json([
                'lift' => [
                    'id' => $liftId,
                    'current_floor' => FloorHelper::getFloorId($current),
                    'direction' => $liftDirection,
                    'next_stops' => array_map(fn($n) => FloorHelper::getFloorId($n['floor']), $stops)
                ],
                'message' => 'Already at requested floor(s) or destinations already queued'
            ]);
        }

        // Sort stops
        $stops = StopSorter::sortStops($stops, $current, $liftDirection);
        foreach ($stops as $i => $s) {
            $stops[$i]["direction"] = ($s["floor"] > $current) ? "UP" : "DOWN";
        }

        // Set direction if idle
        if (strtoupper(trim($liftDirection)) === 'IDLE' && !empty($stops)) {
            $firstStop = $stops[0];
            $liftDirection = $firstStop['floor'] > $current ? 'UP' : 'DOWN';
        }

        // Save back to Redis
        Redis::hmset($liftKey, [
            'direction' => $liftDirection,
            'next_stops' => json_encode($stops),
            'updated_at' => now()->toDateTimeString(),
        ]);

        // Return formatted stops for UI
        $formattedStops = array_map(fn($n) => FloorHelper::getFloorId($n['floor']), $stops);

        return response()->json([
            'lift' => [
                'id' => $liftId,
                'current_floor' => FloorHelper::getFloorId($current),
                'direction' => $liftDirection,
                'next_stops' => $formattedStops
            ],
            'message' => 'Destinations added successfully'
        ]);
    }






        /**
     * Class CancelLiftService
     *
     * This service handles the cancellation of lift requests.
     * When a user cancels a request, the destination floor must be removed
     * from the lift's `next_stops` queue stored in Redis.
     *
     * Responsibilities:
     * - Validate lift existence
     * - Fetch and modify next_stops list
     * - Remove specific floor from queue
     * - Update Redis with new queue
     *
     * This service keeps cancellation logic separate from the controller,
     * improving testability, readability, and maintainability.
     */

    public function cancelLift($liftId, array $floors)
    {
        if (empty($floors)) {
            return response()->json(['error' => 'No floors provided'], 400);
        }

        // Redis key for this lift
        $liftKey = "lift:l{$liftId}";

        // Fetch lift from Redis
        $liftData = Redis::hgetall($liftKey);

        if (empty($liftData)) {
            return response()->json(['error' => 'Lift not found'], 404);
        }

        $current = isset($liftData['current_floor']) ? (int)$liftData['current_floor'] : 1;
        $stops = !empty($liftData['next_stops']) ? json_decode($liftData['next_stops'], true) : [];
        $liftDirection = $liftData['direction'] ?? 'IDLE';

        // Convert floor strings to numbers
        $floorsToRemove = [];
        foreach ($floors as $floorString) {
            $num = FloorHelper::getFloorNo($floorString);

            if ($num === null) {
                return response()->json([
                    "error" => "Invalid floor: $floorString"
                ], 422);
            }
            $floorsToRemove[] = $num;
        }

        // Remove stops matching these floors
        $stops = array_values(array_filter($stops, function ($stop) use ($floorsToRemove) {
            return !isset($stop['floor']) || !in_array($stop['floor'], $floorsToRemove);
        }));

        // Recalculate direction
        if (empty($stops)) {
            $liftDirection = 'IDLE';
        } else {
            // Sort stops based on existing direction
            $stops = StopSorter::sortStops($stops, $current, $liftDirection);

            $nextStop = $stops[0]['floor'] ?? $current;

            if ($nextStop > $current) {
                $liftDirection = 'UP';
            } elseif ($nextStop < $current) {
                $liftDirection = 'DOWN';
            } else {
                $liftDirection = 'IDLE';
            }
        }

        // Save back to Redis
        Redis::hmset($liftKey, [
            'direction' => $liftDirection,
            'next_stops' => json_encode($stops),
            'updated_at' => now()->toDateTimeString(),
        ]);

        // Convert numeric stops back to string for UI
        $formattedStops = array_map(fn($stop) => FloorHelper::getFloorId($stop['floor']), $stops);

        return response()->json([
            "message" => "Stops removed successfully",
            "remainingStops" => $formattedStops,
            "direction" => $liftDirection
        ]);
    }
}
