<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Models\Lift;

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
