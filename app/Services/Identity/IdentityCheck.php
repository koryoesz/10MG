<?php

namespace App\Services\Identity;

/**
 * The outcome of verifying one identity number, after applying the matching rules.
 */
final readonly class IdentityCheck
{
    /**
     * @param  array<string, bool>  $fieldMatches
     * @param  list<string>  $failedFields  Required fields that did not match.
     */
    public function __construct(
        public string $type,
        public bool $verified,
        public ?string $reference,
        public array $fieldMatches,
        public array $failedFields,
        public ?string $reason = null,
    ) {}

    /**
     * @return array{verified: bool, reference: string|null, field_matches: array<string, bool>, failed_fields: list<string>, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'verified' => $this->verified,
            'reference' => $this->reference,
            'field_matches' => $this->fieldMatches,
            'failed_fields' => $this->failedFields,
            'reason' => $this->reason,
        ];
    }
}
