<?php

namespace App\Services\Identity;

/**
 * A register's answer: whether it found the number, and which submitted fields matched its record.
 */
final readonly class ProviderMatch
{
    /**
     * @param  array<string, bool>  $fieldMatches  e.g. `['first_name' => true, 'date_of_birth' => false]`
     */
    public function __construct(
        public string $provider,
        public bool $found,
        public array $fieldMatches,
        public ?string $reference,
        public string $responseCode,
        public string $responseMessage,
    ) {}

    public function matched(string $field): bool
    {
        return $this->fieldMatches[$field] ?? false;
    }
}
