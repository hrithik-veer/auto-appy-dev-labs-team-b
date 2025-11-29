<?php

namespace App\Services;

use App\Models\Lift;
use Illuminate\Support\Facades\DB;
use App\Helpers\FloorHelper;
use App\Helpers\StopSorter;
use App\Models\Lift as ModelsLift;
use Illuminate\Support\Facades\Redis;





/**
 * Class LiftCaller
 *
 * The LiftCaller service is responsible for selecting the most optimal lift
 * when a user requests a ride.
 *
 * This class is used by RequestLiftService and acts as a dedicated
 * "lift selection engine".
 *
 * ---------------------------------------------------------
 * Responsibilities:
 * ---------------------------------------------------------
 * - Fetch all lifts and their statuses from Redis
 * - Calculate distance between lifts and user floor
 * - Check lift direction and idle positions
 * - Determine the best available lift
 * - Return lift ID to RequestLiftService
 *
 * This class contains all algorithmic logic so that the controller and
 * RequestLiftService remain clean and readable.
 */



class LiftCaller
{
    public function HandletLiftrequest(string $floor, string $direction)
    {
        $requestedFloor = FloorHelper::getFloorNo($floor);

        if ($requestedFloor === null) {
            return response()->json(['error' => 'Invalid floor'], 400);
        }

        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['UP', 'DOWN'])) {
            return response()->json(['error' => 'Invalid direction'], 400);
        }

        // ------------------ FETCH ALL LIFTS FROM REDIS ------------------
        $liftKeys = Redis::keys("lift:*");
        if (empty($liftKeys)) {
            return response()->json(['error' => 'No lifts in Redis'], 503);
        }

        $lifts = [];
        foreach ($liftKeys as $key) {
            $data = Redis::hgetall($key);
            $data = [
                'liftId'        => $data['liftId'] ?? str_replace("lift:", "", $key),
                'current_floor' => isset($data['current_floor']) ? (int)$data['current_floor'] : null,
                'direction'     => $data['direction'] ?? 'IDLE',
                'status'        => $data['status'] ?? 'idle',
                'next_stops'    => !empty($data['next_stops'])
                    ? json_decode($data['next_stops'], true)
                    : [],
            ];

            $lifts[] = $data;
        }

        // ------------------ CHECK: Already queued on any lift ------------------
        foreach ($lifts as $lift) {
            foreach ($lift['next_stops'] as $stop) {
                if (
                    $stop['floor'] == $requestedFloor &&
                    strtoupper($stop['direction']) == $direction
                ) {
                    return response()->json(['liftId' => $lift['liftId']]);
                }
            }
        }

        // ------------------ SORT LIFTS INTO GROUPS ------------------
        $up = [];
        $down = [];
        $idle = [];

        foreach ($lifts as $lift) {
            $ld = strtoupper(trim($lift['direction']));
            if ($ld === 'UP') $up[] = $lift;
            elseif ($ld === 'DOWN') $down[] = $lift;
            else $idle[] = $lift;
        }

        $chosen = null;
        $minTime = PHP_INT_MAX;

        // ------------------ PRIORITY 1: Idle lifts ------------------
        foreach ($idle as $lift) {
            $cf = (int) $lift['current_floor'];
            $t = abs($cf - $requestedFloor);
            if ($t < $minTime) {
                $minTime = $t;
                $chosen = $lift;
            }
        }

        // ------------------ PRIORITY 2: Same direction ------------------
        $sameDir = $direction === 'UP' ? $up : $down;

        foreach ($sameDir as $lift) {
            $cf = (int) $lift["current_floor"];
            $stops = $lift['next_stops'];

            if ($direction === 'UP') {
                if ($cf <= $requestedFloor) {
                    $t = $this->calculateTimeWithStops($cf, $requestedFloor, $stops, 'UP', $lift['direction']);
                } else {
                    $highest = !empty($stops) ? max(array_column($stops, "floor")) : $cf;
                    $t = ($highest - $cf) + ($highest - $requestedFloor);
                }
            } else {
                if ($cf >= $requestedFloor) {
                    $t = $this->calculateTimeWithStops($cf, $requestedFloor, $stops, 'DOWN', $lift['direction']);
                } else {
                    $lowest = !empty($stops) ? min(array_column($stops, "floor")) : $cf;
                    $t = ($cf - $lowest) + ($requestedFloor - $lowest);
                }
            }

            if ($t < $minTime) {
                $minTime = $t;
                $chosen = $lift;
            }
        }

        // ------------------ PRIORITY 3: Opposite direction ------------------
        $opposite = $direction === 'UP' ? $down : $up;

        foreach ($opposite as $lift) {
            $cf = (int) $lift['current_floor'];
            $stops = $lift['next_stops'];

            if ($lift['direction'] === 'UP') {
                $highest = !empty($stops)
                    ? max(array_column($stops, "floor"))
                    : FloorHelper::maxNumber();
                $finishTime = $highest - $cf;
                $finishPoint = $highest;
            } else {
                $lowest = !empty($stops)
                    ? min(array_column($stops, "floor"))
                    : FloorHelper::minNumber();
                $finishTime = $cf - $lowest;
                $finishPoint = $lowest;
            }

            $travel = abs($finishPoint - $requestedFloor);
            $penalty = 2;

            $t = $finishTime + $travel + $penalty;

            if ($t < $minTime) {
                $minTime = $t;
                $chosen = $lift;
            }
        }

        if (!$chosen) {
            return response()->json(['error' => 'No suitable lift found'], 503);
        }

        // ------------------ UPDATE THE CHOSEN LIFT IN REDIS ------------------
        $liftKey = "lift:" . $chosen['liftId'];

        $currentFloor     = (int)$chosen['current_floor'];
        $currentDirection = strtoupper($chosen['direction']);
        $stops            = $chosen['next_stops'];

        // Extract floor numbers
        $floorList = array_column($stops, "floor");

        // Add new stop if needed
        if ($currentFloor !== $requestedFloor && !in_array($requestedFloor, $floorList)) {
            $stops[] = [
                "floor" => $requestedFloor,
                "direction" => $direction
            ];
        }

        // If lift was idle, assign a direction
        if ($currentDirection === 'IDLE' && !empty($stops)) {
            $currentDirection = $requestedFloor > $currentFloor ? 'UP' : 'DOWN';
            $this->saveLiftToDB($chosen['liftId']);
        }

        // Sort stops based on movement
        $sortedStops = StopSorter::sortStops(
            $stops,
            $currentFloor,
            $currentDirection
        );

        // Save updated values to Redis
        Redis::hset($liftKey, 'direction', $currentDirection);
        Redis::hset($liftKey, 'next_stops', json_encode($sortedStops));
        Redis::hset($liftKey, 'updated_at', now()->toDateTimeString());

        return response()->json(['liftId' => $chosen['liftId'][1]]);
    }


    public function calculateTimeWithStops(int $currentFloor, int $targetFloor, array $stops, string $requestedDirection, string $liftDirection = 'IDLE'): int
    {
        $requestedDirection = strtoupper($requestedDirection);
        $liftDirection = strtoupper($liftDirection);

        // If no stops, just return base distance
        if (empty($stops)) {
            // If lift is idle, just distance
            return abs($targetFloor - $currentFloor);
        }

        $stopsInDirection = [];

        // Filter stops that are in the same direction as the lift
        if ($liftDirection === 'UP') {
            $stopsInDirection = array_filter($stops, fn($s) => $s['direction'] === 'UP' && $s['floor'] >= $currentFloor);
            $finishPoint = !empty($stopsInDirection) ? max(array_column($stopsInDirection, 'floor')) : max(array_column($stops, 'floor'));
            $finishTime = $finishPoint - $currentFloor;
        } elseif ($liftDirection === 'DOWN') {
            $stopsInDirection = array_filter($stops, fn($s) => $s['direction'] === 'DOWN' && $s['floor'] <= $currentFloor);
            $finishPoint = !empty($stopsInDirection) ? min(array_column($stopsInDirection, 'floor')) : min(array_column($stops, 'floor'));
            $finishTime = $currentFloor - $finishPoint;
        } else {
            // Idle lift, just treat stops as empty
            $finishPoint = $currentFloor;
            $finishTime = 0;
        }

        // Phase 2: travel from finish point to user
        $travelToUser = abs($finishPoint - $targetFloor);

        // Phase 3: add direction-change penalty if lift direction != requested direction
        $directionPenalty = ($liftDirection !== 'IDLE' && $liftDirection !== $requestedDirection) ? 2 : 0;

        // Base time plus number of relevant stops plus penalty
        $t = $finishTime + $travelToUser + count($stopsInDirection) + $directionPenalty;

        return $t;
    }
    public static function saveLiftToDB($liftId)
    {
        $liftKey = "lift:$liftId";  // lift:l1
        $data = Redis::hgetall($liftKey);

        $model = Lift::where('lift_id', $liftId)->first();
        if ($model && !empty($data)) {
            $model->current_floor = $data['current_floor'];
            // $model->direction = $data['direction'];
            // $model->next_stops = $data['next_stops'];
            // $model->status = $data['status'];
            // $model->updated_at = now()->toDateTimeString();
            $model->save();
        }
    }


}