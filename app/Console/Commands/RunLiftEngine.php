<?php

namespace App\Console\Commands;

$LIFT_OPENING_TIME = config('constants.LIFT_OPENING_TIME');
$LIFT_TRAVELLING_TIME = config('constants.LIFT_TRAVELLING_TIME');

use App\Console\Commands\LoadLiftsToRedis as CommandsLoadLiftsToRedis;
use Illuminate\Support\Facades\Redis;
use App\Services\LiftCaller;
use Illuminate\Console\Command;
use App\Console\Commands\LoadLiftsToRedis;
use App\Models\Lift;

class RunLiftEngine extends Command
{
    protected $signature = 'lift:run';
    protected $description = 'Move all lifts continuously like a real elevator engine';

    public function handle()
    {
        $this->info("Lift engine started...");

        if (Redis::keys("lift:*") === []) {
            LoadLiftsToRedis::handle();
        }

        while (true) {

            $active = false;

            // Fetch all lifts from Redis instead of DB
            $keys = Redis::keys("lift:*");
            $lifts = [];

            foreach ($keys as $key) {
                $data = Redis::hgetall($key);

                $lifts[] = [
                    'key'           => $key,
                    'liftId'        => $data['liftId'],
                    'current_floor' => (int) $data['current_floor'],
                    'direction'     => $data['direction'],
                    'next_stops'    => !empty($data['next_stops'])
                        ? json_decode($data['next_stops'], true)
                        : []
                ];
            }

            foreach ($lifts as $lift) {

                if (count($lift['next_stops']) > 0) {
                    $active = true;
                }

                $this->moveLiftRedis($lift);
            }

            usleep(200000); // 0.2 sec
        }
    }

    private function moveLiftRedis(array $lift)
    {
        $key   = $lift['key'];
        $stops = $lift['next_stops'];

        if (empty($stops)) {
            Redis::hset($key, 'direction', 'IDLE');
            return;
        }

        $current = $lift['current_floor'];
        $next    = is_array($stops[0]) ? $stops[0]["floor"] : $stops[0];

        // ----------- MOVE UP -------------------
        if ($current < $next) {
            sleep(config('constants.LIFT_TRAVELLING_TIME'));

            $newFloor = $current + 1;

            Redis::hset($key, 'current_floor', $newFloor);
            Redis::hset($key, 'direction', 'UP');

            $this->info("Lift {$lift['liftId']} → $newFloor");
            return;
        }

        // ----------- MOVE DOWN -------------------
        if ($current > $next) {
            sleep(config('constants.LIFT_TRAVELLING_TIME'));

            $newFloor = $current - 1;

            Redis::hset($key, 'current_floor', $newFloor);
            Redis::hset($key, 'direction', 'DOWN');

            $this->info("Lift {$lift['liftId']} → $newFloor");
            return;
        }

        // ----------- REACHED THE STOP -------------------
        if ($current == $next) {

            $this->info("Lift {$lift['liftId']} reached floor $current");
            $this->info("Doors opening...");

            sleep(config('constants.LIFT_OPENING_TIME'));

            // Remove reached stop
            array_shift($stops);

            // Set correct direction
            $newDirection = empty($stops) ? "IDLE" : $lift['direction'];
            if ($newDirection == "IDLE") {
                // $this->info($lift['liftId']);
                LiftCaller::saveLiftToDB($lift['liftId']);
            }

            Redis::hset($key, 'direction', $newDirection);
            Redis::hset($key, 'next_stops', json_encode(array_values($stops)));
        }
    }
}
