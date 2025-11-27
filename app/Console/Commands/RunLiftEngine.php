<?php

namespace App\Console\Commands;

$LIFT_OPENING_TIME = config('constants.LIFT_OPENING_TIME');
$LIFT_TRAVELLING_TIME = config('constants.LIFT_TRAVELLING_TIME');


use Illuminate\Console\Command;
use App\Models\Lift;

class RunLiftEngine extends Command
{
    protected $signature = 'lift:run';
    protected $description = 'Move all lifts continuously like a real elevator engine';
    
    public function handle()
    {
        
        $this->info("🚀 Lift engine started...");

        while (true) {

            $active = false;
            $lifts = Lift::all();

            foreach ($lifts as $lift) {

                if (is_array($lift->next_stops) && count($lift->next_stops) > 0) {
                    $active = true;
                }

                $this->moveLift($lift);
            }

            // Do NOT stop engine; just keep running
            if (!$active) {
                $this->info("");
            }

            usleep(200000); // 0.2 sec
        }
    }

    private function moveLift(Lift $lift)
    {
        global $LIFT_OPENING_TIME;
        global $LIFT_TRAVELLING_TIME;
        $stops = $lift->next_stops;

        if (!is_array($stops) || count($stops) === 0) {
            $lift->direction = "IDLE";
            $lift->save();
            return;
        }

        $current = $lift->current_floor;
        $next = $stops[0]["floor"];

        if ($current < $next) {
            sleep(config('constants.LIFT_TRAVELLING_TIME'));
            $lift->current_floor = $current + 1;
            $lift->direction = "UP";
            $lift->save();
            $this->info("Lift {$lift->lift_id} → " . ($current + 1));
            return;
        }

        if ($current > $next) {
            sleep(config('constants.LIFT_TRAVELLING_TIME'));
            $lift->current_floor = $current - 1;
            $lift->direction = "DOWN";
            $lift->save();
            $this->info("Lift {$lift->lift_id} → " . ($current - 1));
            return;
        }

        if ($current == $next) {
            $this->info("Lift {$lift->lift_id} reached floor $current");
            $this->info("Doors opening...");
            sleep(config('constants.LIFT_OPENING_TIME'));

            array_shift($stops);

            $lift->direction = empty($stops) ? "IDLE" : $lift->direction;
            $lift->next_stops = array_values($stops);

            $lift->save();
        }
    }



   
}
