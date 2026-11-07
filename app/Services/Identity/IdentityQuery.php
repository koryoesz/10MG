<?php

namespace App\Services\Identity;

use Carbon\CarbonImmutable;

/**
 * The details a person claims, sent to an identity register to be matched against its record.
 */
final readonly class IdentityQuery
{
    public function __construct(
        public string $number,
        public string $firstName,
        public string $lastName,
        public CarbonImmutable $dateOfBirth,
        public string $phone,
        public ?string $middleName = null,
    ) {}

    /**
     * The same person, queried with a different identity number (BVN → NIN).
     */
    public function withNumber(string $number): self
    {
        return new self($number, $this->firstName, $this->lastName, $this->dateOfBirth, $this->phone, $this->middleName);
    }
}
