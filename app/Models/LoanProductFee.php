<?php

namespace App\Models;

use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use App\Support\Tenancy\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'calculation', 'value', 'charge_type'])]
class LoanProductFee extends Model
{
    use BelongsToInstitution, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculation' => FeeCalculation::class,
            'charge_type' => FeeChargeType::class,
            'value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LoanProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
