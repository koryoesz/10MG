<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Borrower self-service: refresh trading figures, contact details and renew the operating
 * licence. Legal identity (CAC number, business type) cannot be changed here.
 */
class UpdateBusinessProfileRequest extends FormRequest
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
            'monthly_revenue' => ['sometimes', 'integer', 'min:0', 'max:'.config('lending.max_amount')],
            'monthly_expenses' => ['sometimes', 'integer', 'min:0', 'max:'.config('lending.max_amount')],
            'staff_count' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'state' => ['sometimes', 'string', 'max:100'],
            /** Renewing a licence requires both the new number and its expiry date. */
            'licence_number' => ['required_with:licence_expires_at', 'string', 'max:64'],
            'licence_expires_at' => ['required_with:licence_number', 'date', 'after:today'],
            'registration_number' => ['prohibited'],
            'business_type' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_number.prohibited' => 'The registration number cannot be changed. Contact your lender to correct it.',
            'business_type.prohibited' => 'The business type cannot be changed. Contact your lender to correct it.',
            'licence_expires_at.after' => 'The renewed licence must expire in the future.',
        ];
    }
}
