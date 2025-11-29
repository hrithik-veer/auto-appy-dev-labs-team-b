<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Models\Lift;





/**
 * Class LoadLiftToRedis
 *
 * Responsible for loading persistent lift data from the database
 * into Redis cache for real-time access by the lift engine and services.
 *
 * ---------------------------------------------------------
 * Responsibilities:
 * ---------------------------------------------------------
 * - Fetch all lift records from the database (Lift model)
 * - Initialize Redis keys for each lift:
 *      - lift:<lift_id> (hash)
 *      - lift:<lift_id>:next_stops (list)
 * - Set default state for lifts (current_floor, direction, next_stops)
 * - Ensure Redis contains all lifts in a ready-to-use state
 *
 * This class is typically called:
 * - On system startup
 * - After reset of all lifts
 * - After clearing Redis cache
 *
 * Benefits:
 * - Improves lift response time
 * - Removes DB dependency during live operations
 * - Keeps lift engine fast and scalable
 */




class LoadLiftsToRedis extends Command
{
    protected $signature = 'lifts:sync-to-redis';
    protected $description = 'Load all lifts from MySQL into Redis';

    public static function handle()
    {
        $lifts = Lift::all();

        foreach ($lifts as $lift) {
            $key = "lift:{$lift->lift_id}";

            Redis::hmset($key, [
                'liftId'       => $lift->lift_id,
                'current_floor' => $lift->current_floor,
                'target_floor' => $lift->target_floor,
                'status'        => $lift->status,
                'direction'     => $lift->direction,
                'door_status'   => $lift->door_status,
                'updated_at'    => now(),
            ]);
        }

        info("Lifts synced to Redis successfully.");
    }
}
