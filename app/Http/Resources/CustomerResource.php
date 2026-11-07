<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A borrowing healthcare business.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'business_name' => $this->business_name,
            'business_type' => $this->business_type,
            'registration_number' => $this->registration_number,
            'licence' => [
                'number' => $this->licence_number,
                'regulator' => $this->business_type->regulator(),
                'expires_at' => $this->licence_expires_at->toDateString(),
                'is_valid' => $this->hasValidLicence(),
            ],
            'year_established' => $this->year_established,
            'years_in_operation' => $this->yearsInOperation(),
            'contact' => [
                'name' => $this->whenLoaded('user', fn () => $this->user->name),
                'email' => $this->whenLoaded('user', fn () => $this->user->email),
                'phone' => $this->phone,
            ],
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'staff_count' => $this->staff_count,
            'monthly_revenue' => $this->monthly_revenue,
            'monthly_expenses' => $this->monthly_expenses,
            'net_monthly_cash_flow' => $this->netMonthlyCashFlow(),
            /** Outcome of the latest verification attempt and the checks passed so far. */
            'kyc' => [
                'status' => $this->kyc_status,
                'checks_passed' => $this->kyc_checks ?? [],
                'verified_at' => $this->kyc_verified_at,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
