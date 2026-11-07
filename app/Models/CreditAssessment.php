<?php

namespace App\Models;

use App\Enums\CreditRecommendation;
use App\Support\Tenancy\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditAssessment extends Model
{
    use BelongsToInstitution, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'recommendation' => CreditRecommendation::class,
            'debt_service_coverage_bps' => 'integer',
            'net_monthly_cash_flow' => 'integer',
            'max_recommended_amount' => 'integer',
            'factors' => 'array',
            'assessed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
