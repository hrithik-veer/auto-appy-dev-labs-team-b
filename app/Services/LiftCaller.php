<?php

namespace App\Services;

use App\Models\Lift;
use Illuminate\Support\Facades\DB;
use App\Helpers\FloorHelper;
use App\Helpers\StopSorter;

class LiftCaller
{
    public function HandletLiftrequest(string $floor, string $direction)
    {
        // $this->startLiftEngineIfNotRunning();

        // Convert UI string → DB integer
        $requestedFloor = FloorHelper::getFloorNo($floor);

        if ($requestedFloor === null) {
            return response()->json(['error' => 'Invalid floor'], 400);
        }

        $direction = strtoupper(trim($direction));

        if (!in_array($direction, ['UP', 'DOWN'])) {
            return response()->json(['error' => 'Invalid direction'], 400);
        }

        return DB::transaction(function () use ($requestedFloor, $direction, $floor) {

            // ---------- NEW: If any lift already has this floor queued, return that lift ----------
            // Use a row lock to make this check atomic with any concurrent assigners.
            // Note: requires next_stops to be a JSON column (or DB supports whereJsonContains).
            $existing = Lift::whereJsonContains('next_stops', [
                'floor' => $requestedFloor,
                'direction' => strtoupper($direction)
            ])->lockForUpdate()->first();

            if ($existing) {
                // If a lift is already queued to stop at this floor, return it immediately.
                return response()->json([
                    'liftId' => $existing->id
                ]);
            }
            // --------------------------------------------------------------------------------------

            // Fetch and lock all lifts for selection (same as before)
            $lifts = Lift::lockForUpdate()->get();

            if ($lifts->isEmpty()) {
                return response()->json(['error' => 'No lifts available'], 503);
            }

            $up = [];
            $down = [];
            $idle = [];

            foreach ($lifts as $lift) {
                $liftDirection = strtoupper(trim($lift->direction));

                if ($liftDirection === 'UP') {
                    $up[] = $lift;
                } elseif ($liftDirection === 'DOWN') {
                    $down[] = $lift;
                } else {
                    $idle[] = $lift;
                }
            }

            $minTime = PHP_INT_MAX;
            $chosen = null;

            $MAX_FLOOR = FloorHelper::maxNumber(); // 17

            /* IDLE LIFTS - Highest Priority */
            foreach ($idle as $lift) {
                $cf = $lift->current_floor;
                $t = abs($cf - $requestedFloor);

                if ($t < $minTime) {
                    $minTime = $t;
                    $chosen = $lift;
                }
            }

            /* SAME DIRECTION - Second Priority */
            $sameDir = $direction === 'UP' ? $up : $down;

            foreach ($sameDir as $lift) {
                $cf = $lift->current_floor;
                $stops = $lift->next_stops ?? [];

                if ($direction === 'UP') {
                    // Only consider if lift hasn't passed requested floor
                    if ($cf <= $requestedFloor) {
                        // Calculate time considering existing stops
                        $t = $this->calculateTimeWithStops($cf, $requestedFloor, $stops, 'UP', $lift->direction);
                    } else {
                        // Lift passed this floor, must complete round trip
                        $highestStop = !empty($stops) ? max($stops) : $cf;
                        $t = ($highestStop - $cf) + ($highestStop - $requestedFloor);
                    }
                } else {
                    // Direction is DOWN
                    if ($cf >= $requestedFloor) {
                        $t = $this->calculateTimeWithStops($cf, $requestedFloor, $stops, 'DOWN', $lift->direction);
                    } else {
                        // Lift passed this floor, must complete round trip
                        $lowestStop = !empty($stops) ? min($stops) : $cf;
                        $t = ($cf - $lowestStop) + ($requestedFloor - $lowestStop);
                    }
                }

                if (isset($t) && $t < $minTime) {
                    $minTime = $t;
                    $chosen = $lift;
                }
            }

            /* OPPOSITE DIRECTION - Last Resort */
            $opposite = $direction === 'UP' ? $down : $up;

            foreach ($opposite as $lift) {

                $cf = $lift->current_floor;
                $stops = $lift->next_stops ?? [];

                // PHASE 1: finish current direction
                if ($lift->direction === 'UP') {
                    $highest = !empty($stops)
                        ? max(array_column($stops, "floor"))
                        : $MAX_FLOOR;

                    $finishTime = $highest - $cf;
                    $finishPoint = $highest;
                } else {
                    $lowest = !empty($stops)
                        ? min(array_column($stops, "floor"))
                        :FloorHelper::minNumber();

                    $finishTime = $cf - $lowest;
                    $finishPoint = $lowest;
                }

                // PHASE 2: travel from finish point to user
                $travelToUser = abs($finishPoint - $requestedFloor);

                // PHASE 3: direction correction penalty
                $directionPenalty = 2; // or 1 or dynamic later

                $t = $finishTime + $travelToUser + $directionPenalty;

                if ($t < $minTime) {
                    $minTime = $t;
                    $chosen = $lift;
                }
            }

            if (!$chosen) {
                return response()->json(['error' => 'No suitable lift found'], 503);
            }

            // Extract only floor numbers from stops
            $stops = $chosen->next_stops;
            $floorList = array_column($stops, "floor");

            // Add requested floor if not already there and not current floor
            if ($chosen->current_floor !== $requestedFloor) {
                if (!in_array($requestedFloor, $floorList)) {
                    $stops[] = [
                        "floor" => $requestedFloor,
                        "direction" => $direction
                    ];
                }
            }

            // Set direction if idle
            if (strtoupper(trim($chosen->direction)) === 'IDLE' && !empty($stops)) {
                $chosen->direction = $requestedFloor > $chosen->current_floor ? 'UP' : 'DOWN';
            }

            // Sort stops based on current direction
            if (!empty($stops)) {
                $stops = StopSorter::sortStops($stops, $chosen->current_floor, $chosen->direction);
            }

            $chosen->next_stops = $stops;
            $chosen->save();

            /* RETURN TO UI WITH STRING FLOOR */
            return response()->json([
                'liftId' => $chosen->id
            ]);
        });
    }

    private function calculateTimeWithStops(int $currentFloor, int $targetFloor, array $stops, string $requestedDirection, string $liftDirection = 'IDLE'): int
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

    /**
     * Sort stops based on current floor and direction
     * UP: serve all floors above first (ascending), then floors below (descending)
     * DOWN: serve all floors below first (descending), then floors above (ascending)
     */
    public function sortStops(array $stops, int $currentFloor, string $direction): array
    {
        $direction = strtoupper(trim($direction));

        // Optional: remove duplicate floors
        $stops = array_values(array_reduce($stops, function ($carry, $item) {
            if (!in_array($item["floor"], array_column($carry, "floor"))) {
                $carry[] = $item;
            }
            return $carry;
        }, []));

        if (empty($stops)) {
            return [];
        }

        $upStops = array_filter($stops, fn($f) => $f["floor"] > $currentFloor);
        $downStops = array_filter($stops, fn($f) => $f["floor"] < $currentFloor);

        usort($upStops, function ($a, $b) {
            return $a['floor'] <=> $b['floor'];   // ascending
        });      // ascending
        usort($downStops, function ($a, $b) {
            return $b['floor'] <=> $a['floor'];   // descending
        });

        // If IDLE, determine direction from closest stop
        if ($direction === 'IDLE') {
            if (!empty($upStops) && !empty($downStops)) {
                // Choose direction based on closest stop
                $closestUp = $upStops[0][["floor"]] - $currentFloor;
                $closestDown = $currentFloor - $downStops[0]["floor"];
                $direction = $closestUp <= $closestDown ? 'UP' : 'DOWN';
            } elseif (!empty($upStops)) {
                $direction = 'UP';
            } elseif (!empty($downStops)) {
                $direction = 'DOWN';
            }
        }

        if ($direction === 'UP') {
            return array_values(array_merge($upStops, $downStops));
        } else {
            return array_values(array_merge($downStops, $upStops));
        }
    }
}
