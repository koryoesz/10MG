<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Support\Tenancy\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstallment extends Model
{
    use BelongsToInstitution, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'immutable_date',
            'principal_due' => 'integer',
            'interest_due' => 'integer',
            'fee_due' => 'integer',
            'penalty_due' => 'integer',
            'total_due' => 'integer',
            'principal_paid' => 'integer',
            'interest_paid' => 'integer',
            'fee_paid' => 'integer',
            'penalty_paid' => 'integer',
            'amount_paid' => 'integer',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function outstanding(): int
    {
        return $this->total_due - $this->amount_paid;
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
