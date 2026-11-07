<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterInstitutionRequest extends FormRequest
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
            'institution.name' => ['required', 'string', 'max:255'],
            'institution.slug' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:institutions,slug'],
            'institution.email' => ['required', 'email', 'max:255'],
            'institution.phone' => ['nullable', 'string', 'max:32'],
            'institution.country_code' => ['sometimes', 'string', 'size:2', 'alpha'],
            'institution.currency' => ['sometimes', 'string', 'size:3', 'in:NGN,GHS,KES,ZAR,USD'],
            'admin.name' => ['required', 'string', 'max:255'],
            'admin.email' => ['required', 'email', 'max:255'],
            'admin.password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'institution.slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
        ];
    }
}
