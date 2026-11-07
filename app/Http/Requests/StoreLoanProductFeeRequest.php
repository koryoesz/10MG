<?php

namespace App\Http\Requests;

use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanProductFeeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'calculation' => ['required', Rule::enum(FeeCalculation::class)],
            /** Minor units for `fixed`, basis points for `percentage`. */
            'value' => ['required', 'integer', 'min:0', 'max:'.config('lending.max_amount'), Rule::when($this->input('calculation') === 'percentage', 'max:10000')],
            'charge_type' => ['required', Rule::enum(FeeChargeType::class)],
        ];
    }
}
