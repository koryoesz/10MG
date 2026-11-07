<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListPlatformFeesRequest extends FormRequest
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
            /** Platform operators only; ignored on the lender endpoint. */
            'institution_id' => ['sometimes', 'string', 'size:26'],
            /** Accrued on or after this date (`Y-m-d`). */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            /** Accrued on or before this date (`Y-m-d`). */
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
