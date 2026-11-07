<?php

namespace App\Services\Identity;

use App\Contracts\BvnRegistry;

/**
 * Verifies a Bank Verification Number against NIBSS. Name and date of birth must match the
 * BVN record. A phone mismatch is recorded but only fatal when the lender turns on phone
 * matching, because the phone linked to a BVN is frequently out of date.
 */
class BvnService
{
    /**
     * @var list<string>
     */
    public const REQUIRED_FIELDS = ['first_name', 'last_name', 'date_of_birth'];

    public function __construct(private BvnRegistry $registry) {}

    /**
     * @param  bool  $requirePhoneMatch  Lender setting: also fail when the phone number does not match.
     */
    public function verify(IdentityQuery $query, bool $requirePhoneMatch = false): IdentityCheck
    {
        $required = $requirePhoneMatch ? [...self::REQUIRED_FIELDS, 'phone'] : self::REQUIRED_FIELDS;

        $match = $this->registry->matchBvn($query);

        if (! $match->found) {
            return new IdentityCheck('bvn', false, $match->reference, $match->fieldMatches, $required, 'BVN not found with NIBSS.');
        }

        $failed = array_values(array_filter($required, fn (string $field): bool => ! $match->matched($field)));

        return new IdentityCheck(
            type: 'bvn',
            verified: $failed === [],
            reference: $match->reference,
            fieldMatches: $match->fieldMatches,
            failedFields: $failed,
            reason: $failed === [] ? null : 'Details do not match the BVN record.',
        );
    }
}
