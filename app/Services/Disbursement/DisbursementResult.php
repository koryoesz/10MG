<?php

namespace App\Services\Disbursement;

final readonly class DisbursementResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerReference,
        public ?string $failureReason = null,
    ) {}

    public static function success(string $providerReference): self
    {
        return new self(true, $providerReference);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
