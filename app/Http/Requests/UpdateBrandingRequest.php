<?php

namespace App\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandingRequest extends FormRequest
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
        $hex = 'regex:/^#[0-9A-Fa-f]{6}$/';

        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'logo_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'primary_color' => ['sometimes', 'required', 'string', $hex],
            'secondary_color' => ['sometimes', 'required', 'string', $hex],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'custom_domain' => [
                'sometimes', 'nullable', 'string', 'max:255',
                'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/',
                Rule::unique('institution_brandings', 'custom_domain')->ignore(app(TenantContext::class)->id(), 'institution_id'),
            ],
        ];
    }
}
