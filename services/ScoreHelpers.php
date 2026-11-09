<?php
declare(strict_types=1);

/**
 * services/ScoreHelpers.php
 * Extracted helper functions for score calculations
 * Refactored from ScoreService.php for better code organization
 */

class ScoreHelpers
{
    /**
     * P1. Buffer Zone / Segregation
     * Segregated or Buffer Zone = 0, None/unknown = 100
     */
    public static function bufferZone(?string $bufferZone): float
    {
        return match ($bufferZone ?? '') {
            'Segregated', 'Buffer Zone' => 0.0,
            default                     => 100.0,
        };
    }

    /**
     * P2. Light After Dark
     * Yes=0, Partial=50, No/NULL=100
     */
    public static function lightAfterDark(?string $light): float
    {
        return match ($light ?? '') {
            'Yes'     => 0.0,
            'Partial' => 50.0,
            default   => 100.0,
        };
    }

    /**
     * P3. Traffic Calming at intersections
     * 0 absent=0, 1=50, 2=75, ��3=100
     */
    public static function trafficCalming(int $absentCount): float
    {
        return match (true) {
            $absentCount === 0 => 0.0,
            $absentCount === 1 => 50.0,
            $absentCount === 2 => 75.0,
            default            => 100.0,
        };
    }

    /**
     * P4. Partial Obstructions
     * <5=0, 5–10=50, >10=100
     */
    public static function partialObstructions(float $count): float
    {
        return match (true) {
            $count < 5   => 0.0,
            $count <= 10 => 50.0,
            default      => 100.0,
        };
    }

    /**
     * P5. Missing Ramps
     * 0 missing=0, ≥1=25, ≥3=50, ≥5=100
     */
    public static function missingRamps(int $missingRampCount): float
    {
        return match (true) {
            $missingRampCount === 0 => 0.0,
            $missingRampCount < 3   => 25.0,
            $missingRampCount < 5   => 50.0,
            default                 => 100.0,
        };
    }

    /**
     * P6. Missing Signage and Markings
     * 0 absent=0, 1=50, 2=75, ≥3=100
     */
    public static function missingSignage(int $absentSignCount): float
    {
        return match (true) {
            $absentSignCount === 0 => 0.0,
            $absentSignCount === 1 => 50.0,
            $absentSignCount === 2 => 75.0,
            default                => 100.0,
        };
    }

    /**
     * P7. Total Obstructions
     * <5=0, 5–10=50, >10=100
     */
    public static function totalObstructions(float $count): float
    {
        return match (true) {
            $count < 5   => 0.0,
            $count <= 10 => 50.0,
            default      => 100.0,
        };
    }

    /**
     * P8. Track Surface
     * Concrete/Asphalt=0, Interlock Blocks=100
     */
    public static function surface(?string $material): float
    {
        return match ($material ?? '') {
            'Interlock Blocks', 'Interblocks' => 100.0,
            default                            => 0.0,
        };
    }

    /**
     * P9. Cyclist Slowed Down
     * One scale for every road: <5=0, 5-10=50, 10-20=75, >20=100
     */
    public static function cyclistSlowed(float $count): float
    {
        return match (true) {
            $count < 5   => 0.0,
            $count <= 10 => 50.0,
            $count <= 20 => 75.0,
            default      => 100.0,
        };
    }

    /**
     * P10. Shade
     * Yes=0, Partial=50, No=100, NULL/unknown=0
     */
    public static function shade(?string $shade): float
    {
        return match ($shade ?? '') {
            'No'      => 100.0,
            'Partial' => 50.0,
            default   => 0.0,
        };
    }

    /**
     * Apply missing-track adjustment to raw category score
     */
    public static function applyMissingLength(float $rawScore, float $missingLen, float $presentLen, float $totalLen): float
    {
        if ($totalLen <= 0.0) return 100.0;
        if ($presentLen <= 0.0) return 100.0;
        return (100.0 * $missingLen + $rawScore * $presentLen) / $totalLen;
    }

    /**
     * Convert score to condition label
     */
    public static function scoreToCondition(float $score): string
    {
        return match (true) {
            $score <= 20 => 'Good',
            $score <= 40 => 'OK',
            $score <= 60 => 'Poor',
            $score <= 80 => 'Bad',
            default      => 'Very Bad',
        };
    }

    /**
     * Get condition color
     */
    public static function conditionColor(string $condition): string
    {
        return match ($condition) {
            'Good'     => '#27ae60',
            'OK'       => '#f1c40f',
            'Poor'     => '#e67e22',
            'Bad'      => '#e74c3c',
            'Very Bad' => '#8e1010',
            default    => '#95a5a6',
        };
    }
}
