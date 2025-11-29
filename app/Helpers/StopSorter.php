<?php

namespace App\Helpers;

class StopSorter
{
    public static function sortStops(array $stops, int $currentFloor, string $direction): array
    {
        $direction = strtoupper(trim($direction));

        // Convert "12" or 12 into ["floor" => 12] ONLY for sorting logic
        $stops = array_map(function ($s) {
            if (is_string($s) || is_int($s)) {
                return ["floor" => (int)$s, "original" => $s];
            }
            return $s;
        }, $stops);

        // Filter out invalid items
        $stops = array_values(array_filter($stops, fn($s) => isset($s["floor"])));

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
        });

        usort($downStops, function ($a, $b) {
            return $b['floor'] <=> $a['floor'];   // descending
        });

        // If IDLE, determine direction from closest stop
        if ($direction === 'IDLE') {

            if (!empty($upStops) && !empty($downStops)) {

                // BUG FIX HERE ↓↓↓↓↓
                $closestUp = $upStops[0]["floor"] - $currentFloor;  // ✔ FIXED
                $closestDown = $currentFloor - $downStops[0]["floor"];

                $direction = $closestUp <= $closestDown ? 'UP' : 'DOWN';

            } elseif (!empty($upStops)) {
                $direction = 'UP';

            } elseif (!empty($downStops)) {
                $direction = 'DOWN';
            }
        }

        return $direction === 'UP'
            ? array_values(array_merge($upStops, $downStops))
            : array_values(array_merge($downStops, $upStops));
    }
}
