<?php

namespace App\Http\Requests;

use App\Enums\PlatformFeeBearer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 10MG's platform fee for one lender.
 */
class UpdatePlatformFeeRequest extends FormRequest
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
            /** Basis points of the loan principal: `50` = 0.5%. `0` waives the fee. */
            'rate_bps' => ['required', 'integer', 'min:0', 'max:'.config('lending.platform_fee.max_rate_bps')],
            /** `lender`: a commission the lender owes 10MG. `borrower`: an upfront fee on the loan, shown in quotes. */
            'bearer' => ['required', Rule::enum(PlatformFeeBearer::class)],
        ];
    }
}
