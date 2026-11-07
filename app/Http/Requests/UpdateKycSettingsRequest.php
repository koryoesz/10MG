<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial update of a lender's KYC policy. Send only the switches to change.
 */
class UpdateKycSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** Borrowers must pass every enabled check before applying for a loan. */
            'kyc_required' => ['sometimes', 'boolean'],
            /** Match the owner's BVN with NIBSS. */
            'bvn_check_enabled' => ['sometimes', 'boolean'],
            /** Match the owner's NIN with NIMC. */
            'nin_check_enabled' => ['sometimes', 'boolean'],
            /** Also require the phone number to match the register (stricter; BVN phones are often stale). */
            'phone_match_required' => ['sometimes', 'boolean'],
        ];
    }
}
