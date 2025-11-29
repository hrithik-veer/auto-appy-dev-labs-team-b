<?php

namespace App\Helpers;




/**
 * Class FloorHelper
 *
 * Utility helper class for performing all floor-related calculations
 * and validations in the lift system.
 *
 * ---------------------------------------------------------
 * Responsibilities:
 * ---------------------------------------------------------
 * - Validate requested floors
 * - Check building floor range (min/max)
 * - Normalize floor values (convert to int)
 * - Provide helper functions for direction logic
 * - Support RequestLiftService, LiftCaller, and LiftEngine
 *
 * This class keeps low-level utility logic separate from services,
 * improving reusability, cleanliness, and maintainability.
 */



class FloorHelper
{
    // FLOOR STRING → NUMBER (DB)
    public static array $floorMapping = [
        "B2" => 1,
        "B1" => 2,
        "LG" => 3,
        "G"  => 4,
        "UG" => 5,
        "1"  => 6,
        "2"  => 7,
        "3"  => 8,
        "4"  => 9,
        "5"  => 10,
        "6"  => 11,
        "7"  => 12,
        "8"  => 13,
        "9"  => 14,
        "10" => 15,
        "11" => 16,
        "12" => 17
    ];

    // STRING → INTEGER
    public static function getFloorNo(string $floorId): ?int
    {
        return self::$floorMapping[$floorId] ?? null;
    }

    // INTEGER → STRING
    public static function getFloorId(int $floorNo): ?string
    {
        $reverse = array_flip(self::$floorMapping);
        return $reverse[$floorNo] ?? null;
    }

    // Return all floors in order (for UI)
    public static function getAllFloors(): array
    {
        return array_keys(self::$floorMapping);
    }

    // Return max floor number
    public static function maxNumber(): int
    {
        return max(self::$floorMapping);
    }
    public static function minNumber(): int
    {
        return min(self::$floorMapping);
    }
}
