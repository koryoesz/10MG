<?php

namespace App\Http\Requests;

use App\Enums\BusinessType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends FormRequest
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
            /** Matches business name, CAC number or phone. */
            'search' => ['sometimes', 'string', 'max:100'],
            'business_type' => ['sometimes', Rule::enum(BusinessType::class)],
            'state' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
