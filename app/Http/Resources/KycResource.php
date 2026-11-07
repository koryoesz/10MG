<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\InstitutionKycSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * KYC status of a borrowing business, judged against the lender's current settings.
 * Identity numbers are only ever shown masked.
 *
 * @mixin Customer
 */
class KycResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InstitutionKycSetting $settings */
        $settings = $this->getRelation('kycSettings');
        $owner = $this->owner;

        return [
            'customer_id' => $this->id,
            /** `verified`, `pending`, `failed`, or `not_required` when the lender runs no checks. */
            'status' => $this->kycStatusUnder($settings),
            'can_apply' => $this->meetsKycRequirements($settings),
            'checks_passed' => $this->kyc_checks ?? [],
            'outstanding_checks' => $this->outstandingKycChecks($settings),
            'requirements' => [
                'kyc_required' => $settings->kyc_required,
                'checks' => $settings->enabledChecks(),
                'phone_match_required' => $settings->phone_match_required,
            ],
            'verified_at' => $this->kyc_verified_at,
            'owner' => $owner === null ? null : [
                'name' => $owner->fullName(),
                'date_of_birth' => $owner->date_of_birth->toDateString(),
                'phone' => $owner->phone,
                'bvn' => $this->identity($owner->bvn_last4, $owner->bvn_verified_at, $owner->bvn_reference),
                'nin' => $this->identity($owner->nin_last4, $owner->nin_verified_at, $owner->nin_reference),
            ],
        ];
    }

    /**
     * @return array{masked: string, verified: bool, verified_at: mixed, reference: string|null}|null
     */
    private function identity(?string $lastFour, mixed $verifiedAt, ?string $reference): ?array
    {
        if ($lastFour === null) {
            return null;
        }

        return [
            'masked' => '*******'.$lastFour,
            'verified' => $verifiedAt !== null,
            'verified_at' => $verifiedAt,
            'reference' => $reference,
        ];
    }
}
