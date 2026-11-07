<?php

namespace App\Enums;

enum LoanStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Disbursed = 'disbursed';
    case Repaid = 'repaid';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Disbursed, self::Cancelled],
            self::Disbursed => [self::Repaid],
            self::Rejected, self::Cancelled, self::Repaid => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Statuses that count towards a customer's existing exposure.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Approved, self::Disbursed];
    }
}
