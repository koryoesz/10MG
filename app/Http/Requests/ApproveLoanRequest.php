<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApproveLoanRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:1000'],
            /** Required when the credit assessment recommends `decline`. */
            'override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ];
    }
}
