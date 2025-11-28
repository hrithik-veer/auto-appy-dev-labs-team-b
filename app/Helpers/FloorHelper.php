<?php

namespace App\Helpers;

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
