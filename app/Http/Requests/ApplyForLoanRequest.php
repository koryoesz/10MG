<?php

namespace App\Http\Requests;

use App\Enums\LoanPurpose;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyForLoanRequest extends FormRequest
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
            'loan_product_id' => [
                'required', 'string',
                Rule::exists('loan_products', 'id')
                    ->where('institution_id', app(TenantContext::class)->id())
                    ->whereNull('deleted_at'),
            ],
            /** Principal in minor units. */
            'amount' => ['required', 'integer', 'min:1', 'max:'.config('lending.max_amount')],
            /** Number of installments. */
            'tenor' => ['required', 'integer', 'min:1', 'max:520'],
            'purpose' => ['required', Rule::enum(LoanPurpose::class)],
            'purpose_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
