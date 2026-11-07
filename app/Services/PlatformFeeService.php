<?php

namespace App\Services;

use App\Enums\FeeCalculation;
use App\Enums\FeeChargeType;
use App\Enums\PlatformFeeBearer;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\PlatformFee;
use App\Repositories\Contracts\InstitutionRepositoryInterface;
use App\Repositories\Contracts\PlatformFeeRepositoryInterface;
use App\Services\Lending\FeeTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 10MG's own fee on every disbursed loan. A platform operator sets the rate and who bears
 * it per lender; the terms are snapshotted onto each loan when the borrower applies, and
 * the fee is recorded in the platform revenue ledger when the loan is disbursed.
 */
class PlatformFeeService
{
    public const FEE_NAME = '10MG platform fee';

    public function __construct(
        private InstitutionRepositoryInterface $institutions,
        private PlatformFeeRepositoryInterface $fees,
        private AuditLogger $audit,
    ) {}

    /**
     * Set a lender's platform fee terms (platform operators only). Applies to new applications.
     */
    public function configure(Institution $institution, int $rateBps, PlatformFeeBearer $bearer): Institution
    {
        return DB::transaction(function () use ($institution, $rateBps, $bearer): Institution {
            $institution = $this->institutions->lockForUpdate($institution);
            $before = $this->termsOf($institution);

            $institution->platform_fee_bps = $rateBps;
            $institution->platform_fee_bearer = $bearer;
            $this->institutions->save($institution);

            $this->audit->record('institution.platform_fee_updated', $institution, $before, $this->termsOf($institution));

            return $institution->load(['branding', 'kycSettings']);
        });
    }

    /**
     * The fee charged to the borrower as an upfront loan fee, or null when the lender bears it.
     */
    public function borrowerFeeTerm(Institution $institution): ?FeeTerm
    {
        if ($institution->platformFeeBearer() !== PlatformFeeBearer::Borrower || $institution->platformFeeRateBps() === 0) {
            return null;
        }

        return new FeeTerm(self::FEE_NAME, FeeCalculation::Percentage, $institution->platformFeeRateBps(), FeeChargeType::Upfront);
    }

    /**
     * Loan columns snapshotting the terms in force when the borrower applies.
     *
     * @return array{platform_fee_bps: int, platform_fee_bearer: PlatformFeeBearer, platform_fee_amount: int}
     */
    public function snapshotFor(Institution $institution, int $principal): array
    {
        $rate = $institution->platformFeeRateBps();

        return [
            'platform_fee_bps' => $rate,
            'platform_fee_bearer' => $institution->platformFeeBearer(),
            'platform_fee_amount' => FeeCalculation::Percentage->apply($rate, $principal),
        ];
    }

    /**
     * Record the fee in the revenue ledger. Call inside the disbursement transaction.
     */
    public function accrue(Loan $loan): ?PlatformFee
    {
        if ($loan->platform_fee_amount === 0 || $loan->platform_fee_bearer === null) {
            return null;
        }

        return $this->fees->record([
            'institution_id' => $loan->institution_id,
            'loan_id' => $loan->id,
            'bearer' => $loan->platform_fee_bearer,
            'rate_bps' => $loan->platform_fee_bps,
            'base_amount' => $loan->principal_amount,
            'amount' => $loan->platform_fee_amount,
            'currency' => $loan->currency,
            'accrued_at' => now(),
        ]);
    }

    /**
     * @param  array{institution_id?: string, from?: string, to?: string}  $filters
     * @return array{fees: LengthAwarePaginator<int, PlatformFee>, summary: array<string, mixed>}
     */
    public function ledger(array $filters, bool $acrossTenants): array
    {
        return [
            'fees' => $this->fees->paginate($filters, $acrossTenants),
            'summary' => $this->fees->summarise($filters, $acrossTenants),
        ];
    }

    /**
     * @return array{rate_bps: int, bearer: string, is_default: bool}
     */
    public function termsOf(Institution $institution): array
    {
        return [
            'rate_bps' => $institution->platformFeeRateBps(),
            'bearer' => $institution->platformFeeBearer()->value,
            'is_default' => ! $institution->hasCustomPlatformFee(),
        ];
    }
}
