<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum RepaymentFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function periodsPerYear(): int
    {
        return match ($this) {
            self::Weekly => 52,
            self::Biweekly => 26,
            self::Monthly => 12,
        };
    }

    /**
     * The due date of the given installment (1-based) counted from the start date.
     */
    public function dueDate(CarbonImmutable $start, int $sequence): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $start->addWeeks($sequence),
            self::Biweekly => $start->addWeeks($sequence * 2),
            self::Monthly => $start->addMonthsNoOverflow($sequence),
        };
    }
}
