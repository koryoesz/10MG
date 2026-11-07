<?php

namespace App\Services\Lending;

use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use App\Models\LoanProductFee;

/**
 * An immutable fee definition, as snapshotted onto a loan.
 */
final readonly class FeeTerm
{
    public function __construct(
        public string $name,
        public FeeCalculation $calculation,
        public int $value,
        public FeeChargeType $chargeType,
    ) {}

    public static function fromProductFee(LoanProductFee $fee): self
    {
        return new self($fee->name, $fee->calculation, $fee->value, $fee->charge_type);
    }

    /**
     * @param  array{name: string, calculation: string, value: int, charge_type: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            FeeCalculation::from($data['calculation']),
            (int) $data['value'],
            FeeChargeType::from($data['charge_type']),
        );
    }

    public function amountFor(int $principal): int
    {
        return $this->calculation->apply($this->value, $principal);
    }

    /**
     * @return array{name: string, calculation: string, value: int, charge_type: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'calculation' => $this->calculation->value,
            'value' => $this->value,
            'charge_type' => $this->chargeType->value,
        ];
    }
}
