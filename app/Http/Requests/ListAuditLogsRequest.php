<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListAuditLogsRequest extends FormRequest
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
            /** Platform operators only; ignored on the institution endpoint. */
            'institution_id' => ['sometimes', 'string', 'size:26'],
            /** e.g. `loan.approved`. */
            'action' => ['sometimes', 'string', 'max:64'],
            'auditable_id' => ['sometimes', 'string', 'max:26'],
        ];
    }
}
