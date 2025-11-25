<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
