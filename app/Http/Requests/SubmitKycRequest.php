<?php

namespace App\Http\Requests;

use App\Services\KycSettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The business owner's identity, exactly as held by their bank (BVN) and NIMC (NIN).
 * Only the numbers for the checks the lender has enabled are required; others are ignored.
 */
class SubmitKycRequest extends FormRequest
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
        $settings = app(KycSettingsService::class)->current();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after:1900-01-01'],
            /** Phone number linked to the BVN. */
            'phone' => ['required', 'string', 'max:32'],
            /** 11-digit Bank Verification Number. */
            'bvn' => $settings->bvn_check_enabled ? ['required', 'string', 'digits:11'] : ['exclude'],
            /** 11-digit National Identification Number. */
            'nin' => $settings->nin_check_enabled
                ? ['required', 'string', 'digits:11', ...($settings->bvn_check_enabled ? ['different:bvn'] : [])]
                : ['exclude'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bvn.digits' => 'The BVN must be exactly 11 digits.',
            'nin.digits' => 'The NIN must be exactly 11 digits.',
            'nin.different' => 'The NIN must be different from the BVN.',
            'date_of_birth.before_or_equal' => 'The business owner must be at least 18 years old.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['bvn', 'nin'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => preg_replace('/\s+/', '', $this->input($field))]);
            }
        }
    }
}
