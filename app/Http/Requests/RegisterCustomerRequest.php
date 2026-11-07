<?php

namespace App\Http\Requests;

use App\Enums\BusinessType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Onboards a healthcare business together with the owner / primary contact who will log in.
 */
class RegisterCustomerRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('institution_id', $tenantId)],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            /** CAC registration number, e.g. `RC1234567` or `BN1234567`. */
            'registration_number' => [
                'required', 'string', 'max:32', 'regex:/^(RC|BN|IT)\d{4,10}$/i',
                Rule::unique('customers', 'registration_number')->where('institution_id', $tenantId),
            ],
            /** Operating licence from the business type's regulator (PCN, MDCN, MLSCN, State MoH). */
            'licence_number' => ['required', 'string', 'max:64'],
            'licence_expires_at' => ['required', 'date', 'after:today'],
            'year_established' => ['required', 'integer', 'min:1900', 'max:'.now()->year],
            'phone' => ['required', 'string', 'max:32', Rule::unique('customers', 'phone')->where('institution_id', $tenantId)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'staff_count' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            /** Average monthly revenue in minor units (kobo). */
            'monthly_revenue' => ['required', 'integer', 'min:0', 'max:'.config('lending.max_amount')],
            /** Average monthly operating expenses (rent, salaries, stock) in minor units. */
            'monthly_expenses' => ['required', 'integer', 'min:0', 'max:'.config('lending.max_amount')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_number.regex' => 'The registration number must be a CAC number such as RC1234567 or BN1234567.',
            'licence_expires_at.after' => 'The operating licence has expired. Renew it with your regulator before applying.',
        ];
    }

    /**
     * Normalise the CAC number so uniqueness is case-insensitive.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('registration_number'))) {
            $this->merge(['registration_number' => strtoupper(trim($this->input('registration_number')))]);
        }
    }
}
