<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RecordRepaymentRequest extends FormRequest
{
    public const CHANNELS = ['bank_transfer', 'card', 'ussd', 'wallet', 'cash', 'direct_debit'];

    /**
     * Channels a borrower may report themselves. Cash can only be recorded by staff who
     * physically received it.
     */
    public const SELF_SERVICE_CHANNELS = ['bank_transfer', 'card', 'ussd', 'wallet', 'direct_debit'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $channels = $this->user()->hasPermission(Permission::RepaymentsRecord) ? self::CHANNELS : self::SELF_SERVICE_CHANNELS;

        return [
            /** Amount in minor units. */
            'amount' => ['required', 'integer', 'min:1', 'max:'.config('lending.max_amount')],
            /** Borrowers cannot report `cash`; only staff who received it can. */
            'channel' => ['required', 'string', 'in:'.implode(',', $channels)],
            /** The payment provider's reference; each can only ever be applied once per institution. */
            'external_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
