<?php

namespace App\Models;

use App\Enums\PlatformFeeBearer;
use App\Models\Concerns\IsImmutable;
use App\Support\Tenancy\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 10MG's fee on one disbursed loan: append-only platform revenue owed by the lender.
 */
#[WithoutTimestamps]
class PlatformFee extends Model
{
    use BelongsToInstitution, HasUlids, IsImmutable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bearer' => PlatformFeeBearer::class,
            'rate_bps' => 'integer',
            'base_amount' => 'integer',
            'amount' => 'integer',
            'accrued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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
