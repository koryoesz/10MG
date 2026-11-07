<?php

namespace App\Services\Identity;

use App\Contracts\NinRegistry;

/**
 * Verifies a National Identification Number against NIMC. Name and date of birth must match
 * the National Identity Database record.
 */
class NinService
{
    /**
     * @var list<string>
     */
    public const REQUIRED_FIELDS = ['first_name', 'last_name', 'date_of_birth'];

    public function __construct(private NinRegistry $registry) {}

    /**
     * @param  bool  $requirePhoneMatch  Lender setting: also fail when the phone number does not match.
     */
    public function verify(IdentityQuery $query, bool $requirePhoneMatch = false): IdentityCheck
    {
        $required = $requirePhoneMatch ? [...self::REQUIRED_FIELDS, 'phone'] : self::REQUIRED_FIELDS;

        $match = $this->registry->matchNin($query);

        if (! $match->found) {
            return new IdentityCheck('nin', false, $match->reference, $match->fieldMatches, $required, 'NIN not found with NIMC.');
        }

        $failed = array_values(array_filter($required, fn (string $field): bool => ! $match->matched($field)));

        return new IdentityCheck(
            type: 'nin',
            verified: $failed === [],
            reference: $match->reference,
            fieldMatches: $match->fieldMatches,
            failedFields: $failed,
            reason: $failed === [] ? null : 'Details do not match the NIN record.',
        );
    }
}
