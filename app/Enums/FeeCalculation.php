<?php

namespace App\Enums;

enum FeeCalculation: string
{
    /** Value is an amount in minor units. */
    case Fixed = 'fixed';

    /** Value is in basis points (100 = 1%). */
    case Percentage = 'percentage';

    /**
     * Apply this calculation to a base amount (minor units).
     */
    public function apply(int $value, int $baseAmount): int
    {
        return match ($this) {
            self::Fixed => $value,
            self::Percentage => intdiv($baseAmount * $value + 5000, 10000),
        };
    }
}
