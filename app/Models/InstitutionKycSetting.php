<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A lender's choice of identity checks for its borrowers: whether KYC must pass before
 * applying, which registers are consulted (BVN via NIBSS, NIN via NIMC) and how strict
 * the match is.
 */
#[Fillable(['kyc_required', 'bvn_check_enabled', 'nin_check_enabled', 'phone_match_required'])]
class InstitutionKycSetting extends Model
{
    use BelongsToInstitution, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kyc_required' => 'boolean',
            'bvn_check_enabled' => 'boolean',
            'nin_check_enabled' => 'boolean',
            'phone_match_required' => 'boolean',
        ];
    }

    /**
     * Unsaved settings holding the platform defaults, for a lender that has not chosen yet.
     */
    public static function withDefaults(): self
    {
        return new self([
            'kyc_required' => (bool) config('lending.kyc.required'),
            'bvn_check_enabled' => (bool) config('lending.kyc.bvn_check'),
            'nin_check_enabled' => (bool) config('lending.kyc.nin_check'),
            'phone_match_required' => (bool) config('lending.kyc.phone_match'),
        ]);
    }

    /**
     * The checks this lender runs, e.g. `['bvn', 'nin']`.
     *
     * @return list<'bvn'|'nin'>
     */
    public function enabledChecks(): array
    {
        return array_values(array_filter([
            $this->bvn_check_enabled ? 'bvn' : null,
            $this->nin_check_enabled ? 'nin' : null,
        ]));
    }

    public function runsCheck(string $type): bool
    {
        return in_array($type, $this->enabledChecks(), true);
    }
}
