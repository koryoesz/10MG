<?php

namespace App\Services\Lending;

use Carbon\CarbonImmutable;

final readonly class ScheduleLine
{
    public function __construct(
        public int $sequence,
        public CarbonImmutable $dueDate,
        public int $principal,
        public int $interest,
        public int $fee,
        public int $openingBalance,
    ) {}

    public function total(): int
    {
        return $this->principal + $this->interest + $this->fee;
    }

    /**
     * @return array{sequence: int, due_date: string, principal: int, interest: int, fee: int, total: int, opening_principal_balance: int}
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'due_date' => $this->dueDate->toDateString(),
            'principal' => $this->principal,
            'interest' => $this->interest,
            'fee' => $this->fee,
            'total' => $this->total(),
            'opening_principal_balance' => $this->openingBalance,
        ];
    }
}
