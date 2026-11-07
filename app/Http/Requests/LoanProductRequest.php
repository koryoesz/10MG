<?php

namespace App\Http\Requests;

use App\Enums\BusinessType;
use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use App\Enums\InterestMethod;
use App\Enums\RepaymentFrequency;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) and partial update (PATCH/PUT) of a loan product.
 * Amounts are minor units; rates and percentage fees are basis points (100 = 1%).
 */
class LoanProductRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $product = $this->route('loan_product');

        return [
            'name' => [$required, 'string', 'max:255'],
            'code' => [
                $required, 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('loan_products', 'code')
                    ->where('institution_id', app(TenantContext::class)->id())
                    ->ignore($product?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'min_amount' => [$required, 'integer', 'min:1', 'max:'.config('lending.max_amount')],
            'max_amount' => [$required, 'integer', 'min:1', 'max:'.config('lending.max_amount')],
            'min_tenor' => [$required, 'integer', 'min:1', 'max:520'],
            'max_tenor' => [$required, 'integer', 'min:1', 'max:520'],
            /** Annual interest rate in basis points, e.g. 2400 = 24% p.a. */
            'interest_rate_bps' => [$required, 'integer', 'min:0', 'max:100000'],
            'interest_method' => [$required, Rule::enum(InterestMethod::class)],
            'repayment_frequency' => [$required, Rule::enum(RepaymentFrequency::class)],
            'grace_period_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'late_fee_type' => ['sometimes', 'nullable', Rule::enum(FeeCalculation::class)],
            'late_fee_value' => ['sometimes', 'integer', 'min:0', 'max:'.config('lending.max_amount')],
            'min_credit_score' => ['sometimes', 'nullable', 'integer', 'between:300,850'],
            /** Restrict the product to certain provider types; omit or null for all. */
            'eligible_business_types' => ['sometimes', 'nullable', 'array', 'min:1'],
            'eligible_business_types.*' => ['distinct', Rule::enum(BusinessType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'fees' => [$creating ? 'sometimes' : 'prohibited', 'array', 'max:10'],
            'fees.*.name' => ['required', 'string', 'max:100'],
            'fees.*.calculation' => ['required', Rule::enum(FeeCalculation::class)],
            'fees.*.value' => ['required', 'integer', 'min:0', 'max:'.config('lending.max_amount')],
            'fees.*.charge_type' => ['required', Rule::enum(FeeChargeType::class)],
        ];
    }

    /**
     * Cross-field rules, evaluated against the merged (existing + incoming) values on update.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $product = $this->route('loan_product');
            $value = fn (string $key): mixed => $this->input($key, $product?->getAttribute($key));

            if ((int) $value('max_amount') < (int) $value('min_amount')) {
                $validator->errors()->add('max_amount', 'The max amount must be greater than or equal to the min amount.');
            }

            if ((int) $value('max_tenor') < (int) $value('min_tenor')) {
                $validator->errors()->add('max_tenor', 'The max tenor must be greater than or equal to the min tenor.');
            }

            if ($this->input('late_fee_type') === FeeCalculation::Percentage->value && (int) $this->input('late_fee_value') > 10000) {
                $validator->errors()->add('late_fee_value', 'A percentage late fee cannot exceed 10000 basis points (100%).');
            }

            foreach ((array) $this->input('fees', []) as $index => $fee) {
                if (($fee['calculation'] ?? null) === FeeCalculation::Percentage->value && (int) ($fee['value'] ?? 0) > 10000) {
                    $validator->errors()->add("fees.{$index}.value", 'A percentage fee cannot exceed 10000 basis points (100%).');
                }
            }
        }];
    }
}
