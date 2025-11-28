<?php

namespace App\Services;

use App\Models\Lift;
use Illuminate\Support\Facades\DB;
use App\Helpers\FloorHelper;
use Illuminate\Support\Facades\Cache;

use App\Services\LiftCaller;
use App\Helpers\StopSorter;

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

    // Calling Request Lift function inside LiftCaller
    
    protected $LiftCaller;

    public function __construct(LiftCaller $liftCaller)
    {
        $this->liftCaller = $liftCaller;
    }

    public function requestLift(string $floor, string $direction)
    {
        return $this->liftCaller->HandletLiftrequest($floor,$direction);
    }


    /**
     * Add destination floors from inside the lift
     */
    public function addDestination(array $destinations, $liftId)
    {
        if (empty($destinations)) {
            return response()->json(['error' => 'No destinations provided'], 400);
        }

        // We are starting a DB Transaction where all queries inside must either 
        // succeed together or fail together.
        return DB::transaction(function () use ($destinations, $liftId) {

            // Lock lifts
            $lift = Lift::where('id', $liftId)->lockForUpdate()->first();

            // Check If lift exists 
            if (!$lift) {
                return response()->json(['error' => 'Lift not found'], 404);
            }

            $current = $lift->current_floor;
            $stops = $lift->next_stops ?? [];
            $validDestinationsAdded = false;

            foreach ($destinations as $floorString) {

                // Convert B1/G/UG/10 → number
                $destination = FloorHelper::getFloorNo($floorString);

                if ($destination === null) {
                    return response()->json([
                        "error" => "Invalid floor: $floorString"
                    ], 422);
                }

                // Validate floor bounds
                if ($destination < self::MIN_FLOOR || $destination > self::MAX_FLOOR) {
                    return response()->json([
                        "error" => "Floor out of range: $floorString"
                    ], 422);
                }

                // Skip if already at this floor
                if ($destination === $current) {
                    continue;
                }

                // Add to stops if not already there
                if (!in_array($destination, $stops)) {
                    $stops[] = [
                        "floor"=>$destination,
                        "direction"=>null
                    ];
                    $validDestinationsAdded = true;
                }
            }

            // If no valid stops were added
            if (!$validDestinationsAdded) {
                return response()->json([
                    'lift' => [
                        'id' => $lift->id,
                        'current_floor' => FloorHelper::getFloorId($lift->current_floor),
                        'direction' => $lift->direction,
                        'next_stops' => array_map(fn($n) => FloorHelper::getFloorId($n), $stops)
                    ],
                    'message' => 'Already at requested floor(s) or destinations already queued'
                ]);
            }

            // Sort stops FIRST before determining direction
            $stops = StopSorter::sortStops($stops, $current, $lift->direction);
            foreach ($stops as $i => $s) {
                $stops[$i]["direction"] = ($s["floor"] > $current) ? "UP" : "DOWN";
            }

            /** Set direction if idle - use FIRST SORTED stop */
            if (strtoupper(trim($lift->direction)) === 'IDLE' && !empty($stops)) {
                $firstStop = $stops[0]; // Now this is correctly the first sorted stop
                $lift->direction = $firstStop > $current ? 'UP' : 'DOWN';
            }

            $lift->next_stops = $stops;
            $lift->save();

            /** Convert numeric stops back to string for UI */
            $formattedStops = array_map(fn($n) => FloorHelper::getFloorId($n['floor']), $stops);

        });
    }

    /**
     * Cancel lift request(s)
     */
    public function cancelLift($liftId, array $floors)
    {
        if (empty($floors)) {
            return response()->json(['error' => 'No floors provided'], 400);
        }

        return DB::transaction(function () use ($liftId, $floors) {

            // Lock lift row
            $lift = Lift::where('id', $liftId)->lockForUpdate()->first();

            if (!$lift) {
                return response()->json(['error' => 'Lift not found'], 404);
            }

            $stops = $lift->next_stops ?? [];

            // Convert all floor strings to numbers
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

            // Remove floors from queue
            $stops = array_values(array_filter($stops, function ($f) use ($floorsToRemove) {
                return !in_array($f, $floorsToRemove);
            }));

            // Recalculate direction
            if (empty($stops)) {
                $lift->direction = 'IDLE';
            } else {
                $current = $lift->current_floor;

                // Re-sort remaining stops based on current direction
                $stops = StopSorter::sortStops($stops, $current, $lift->direction);

                // Update direction based on next stop
                if (!empty($stops)) {
                    $nextStop = $stops[0];
                    $lift->direction = $nextStop > $current ? 'UP' : 'DOWN';
                }
            }

            $lift->next_stops = $stops;
            $lift->save();

            // Convert stops back to string form for UI
            $formattedStops = array_map(
                fn($n) => FloorHelper::getFloorId($n),
                $stops
            );


        });
    }
}