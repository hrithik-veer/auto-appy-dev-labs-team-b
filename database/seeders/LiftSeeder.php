<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        DB::table('lifts')->insert([
            ['lift_id'=>'l1','current_floor'=>0,'direction'=>'IDLE','status'=>'idle','next_stops'=>json_encode([]),'available_at'=>0,'created_at'=>$now,'updated_at'=>$now],
            ['lift_id'=>'l2','current_floor'=>0,'direction'=>'IDLE','status'=>'idle','next_stops'=>json_encode([]),'available_at'=>0,'created_at'=>$now,'updated_at'=>$now],
            ['lift_id'=>'l3','current_floor'=>0,'direction'=>'IDLE','status'=>'idle','next_stops'=>json_encode([]),'available_at'=>0,'created_at'=>$now,'updated_at'=>$now],
            ['lift_id'=>'l4','current_floor'=>0,'direction'=>'IDLE','status'=>'idle','next_stops'=>json_encode([]),'available_at'=>0,'created_at'=>$now,'updated_at'=>$now],
        ]);
    }
}
