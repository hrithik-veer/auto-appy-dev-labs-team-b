<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;



/**
 * Class Lift
 *
 * Represents a lift (elevator) inside the system.
 *
 * ---------------------------------------------------------
 * Model Responsibilities:
 * ---------------------------------------------------------
 * - Maps to the `lifts` table in the database
 * - Stores static lift information (name, direction,current_floor, etc.)
 * - Used when syncing Redis data back to DB
 * - Provides relationships and attributes for the Lift entity
 *
 * 
 * - current_floor
 * - next_stops
 * - direction
 *
 * These are stored in Redis for fast access.
 *
 * Fields inside the DB represent only **persistent configuration data**.
 *
 * Example table (lifts):
 *  - id (int)
 *  - lift_id (string)
 *  - current_floor (string)
 *  - direction (enum)
 *  - next_stops (json)
 *  - created_at
 *  - updated_at
 */


class Lift extends Model
{
    protected $fillable = ['lift_id','current_floor','direction','status','next_stops','available_at'];

    protected $casts = [
        'current_floor' => 'integer',
        'next_stops' => 'array',
        'available_at' => 'integer',
    ];
    // Use lift_id as a convenient identifier (you can keep id PK)
    public function getRouteKeyName()
    {
        return 'lift_id';
    }
}
