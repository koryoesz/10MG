<?php

namespace App\Http\Requests;

use App\Enums\LoanStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLoansRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(LoanStatus::class)],
            /** Staff only; ignored for borrowers. */
            'customer_id' => ['sometimes', 'string', 'size:26'],
        ];
    }
}
