<?php

namespace Tests\Feature;

use App\Enums\PlatformFeeBearer;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\LoanProduct;
use App\Models\LoanTransaction;
use App\Models\PlatformFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * 10MG's own fee on each disbursed loan, set per lender by a platform operator.
 */
class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $lender;

    private LoanProduct $product;

    private Customer $pharmacy;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lender = Institution::factory()->create();
        $this->product = LoanProduct::factory()->forInstitution($this->lender)->create();
        $this->pharmacy = Customer::factory()->forInstitution($this->lender)->create();
        $this->operator = User::factory()->platformAdmin()->create();
    }

    public function test_by_default_the_lender_bears_half_a_percent_and_the_borrower_cost_is_unchanged(): void
    {
        $this->postJson("/api/v1/tenant/loan-products/{$this->product->id}/quote", ['amount' => 20_000_000, 'tenor' => 4], ['X-Tenant' => $this->lender->slug])
            ->assertOk()
            ->assertJsonPath('data.upfront_fees', 0)
            ->assertJsonPath('data.net_disbursement_amount', 20_000_000);

        $loan = $this->loanFor($this->pharmacy, $this->product, 'disbursed', amount: 20_000_000, tenor: 4);

        $this->assertSame(PlatformFeeBearer::Lender, $loan->platform_fee_bearer);
        $this->assertSame(100_000, $loan->platform_fee_amount);
        $this->assertSame(20_000_000, $loan->net_disbursement_amount);

        $fee = $this->unscoped(PlatformFee::class)->sole();
        $this->assertSame(100_000, $fee->amount);
        $this->assertSame(50, $fee->rate_bps);
        $this->assertSame($loan->id, $fee->loan_id);
    }

    public function test_a_borrower_borne_fee_is_quoted_and_deducted_from_the_payout(): void
    {
        $this->as($this->operator)->patchJson("/api/v1/platform/institutions/{$this->lender->id}/platform-fee", ['rate_bps' => 100, 'bearer' => 'borrower'])
            ->assertOk()
            ->assertJsonPath('data.platform_fee', ['rate_bps' => 100, 'bearer' => 'borrower', 'is_default' => false]);
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/tenant/loan-products/{$this->product->id}/quote", ['amount' => 20_000_000, 'tenor' => 4], ['X-Tenant' => $this->lender->slug])
            ->assertOk()
            ->assertJsonPath('data.upfront_fees', 200_000)
            ->assertJsonPath('data.net_disbursement_amount', 19_800_000)
            ->assertJsonPath('data.fees.0.name', '10MG platform fee')
            ->assertJsonPath('data.fees.0.amount', 200_000);

        $loan = $this->loanFor($this->pharmacy, $this->product, 'disbursed', amount: 20_000_000, tenor: 4);

        $this->assertSame(19_800_000, $loan->net_disbursement_amount);
        $this->assertSame(200_000, (int) $this->unscoped(LoanTransaction::class)->where('loan_id', $loan->id)->where('type', TransactionType::Fee)->sum('amount'));
        $this->assertSame(PlatformFeeBearer::Borrower, $this->unscoped(PlatformFee::class)->sole()->bearer);
    }

    public function test_terms_are_locked_in_when_the_borrower_applies(): void
    {
        $loan = $this->loanFor($this->pharmacy, $this->product, 'approved', amount: 20_000_000, tenor: 4);

        $this->as($this->operator)->patchJson("/api/v1/platform/institutions/{$this->lender->id}/platform-fee", ['rate_bps' => 300, 'bearer' => 'borrower'])->assertOk();

        $this->as($this->staff($this->lender, Role::FinanceOfficer))->idempotentPost("/api/v1/loans/{$loan->id}/disburse")
            ->assertOk()
            ->assertJsonPath('data.net_disbursement_amount', 20_000_000)
            ->assertJsonPath('data.platform_fee', ['rate_bps' => 50, 'bearer' => 'lender', 'amount' => 100_000]);

        $this->assertSame(100_000, $this->unscoped(PlatformFee::class)->sole()->amount);
    }

    public function test_a_waived_fee_records_nothing(): void
    {
        $this->as($this->operator)->patchJson("/api/v1/platform/institutions/{$this->lender->id}/platform-fee", ['rate_bps' => 0, 'bearer' => 'lender'])->assertOk();

        $this->loanFor($this->pharmacy, $this->product, 'disbursed');

        $this->assertSame(0, $this->unscoped(PlatformFee::class)->count());
    }

    public function test_only_platform_operators_set_the_fee_and_changes_are_validated_and_audited(): void
    {
        $this->as($this->staff($this->lender, Role::InstitutionAdmin))
            ->patchJson("/api/v1/platform/institutions/{$this->lender->id}/platform-fee", ['rate_bps' => 0, 'bearer' => 'lender'])
            ->assertForbidden();

        $this->as($this->operator)->patchJson("/api/v1/platform/institutions/{$this->lender->id}/platform-fee", ['rate_bps' => 5000, 'bearer' => 'nobody'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rate_bps', 'bearer'], 'error.details');

        $this->patchJson("/api/v1/platform/institutions/{$this->lender->id}/platform-fee", ['rate_bps' => 75, 'bearer' => 'lender'])->assertOk();

        $entry = AuditLog::query()->where('action', 'institution.platform_fee_updated')->sole();
        $this->assertEquals(['rate_bps' => 50, 'bearer' => 'lender', 'is_default' => true], $entry->old_values);
        $this->assertEquals(['rate_bps' => 75, 'bearer' => 'lender', 'is_default' => false], $entry->new_values);
    }

    public function test_the_operator_sees_revenue_across_lenders_and_each_lender_sees_only_its_own(): void
    {
        $otherLender = Institution::factory()->create();
        $this->loanFor($this->pharmacy, $this->product, 'disbursed', amount: 20_000_000);
        $this->loanFor(
            Customer::factory()->forInstitution($otherLender)->create(),
            LoanProduct::factory()->forInstitution($otherLender)->create(),
            'disbursed',
            amount: 40_000_000,
        );

        $this->as($this->operator)->getJson('/api/v1/platform/platform-fees')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.total_amount', 300_000)
            ->assertJsonPath('meta.summary.by_bearer.lender.count', 2);

        $this->getJson("/api/v1/platform/platform-fees?institution_id={$otherLender->id}")
            ->assertJsonPath('meta.summary.total_amount', 200_000);

        $this->as($this->staff($this->lender, Role::FinanceOfficer))->getJson('/api/v1/platform-fees?institution_id='.$otherLender->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 100_000)
            ->assertJsonPath('meta.terms', ['rate_bps' => 50, 'bearer' => 'lender', 'is_default' => true])
            ->assertJsonPath('meta.summary.total_amount', 100_000);

        $this->as($this->staff($this->lender, Role::LoanOfficer))->getJson('/api/v1/platform-fees')->assertForbidden();
    }

    public function test_borrowers_never_see_a_lender_borne_commission(): void
    {
        $loan = $this->loanFor($this->pharmacy, $this->product, 'pending');

        $this->as($this->pharmacy->user)->getJson("/api/v1/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.platform_fee');

        $this->as($this->staff($this->lender, Role::LoanOfficer))->getJson("/api/v1/loans/{$loan->id}")
            ->assertJsonPath('data.platform_fee.bearer', 'lender');
    }

    public function test_platform_fee_records_are_append_only(): void
    {
        $this->loanFor($this->pharmacy, $this->product, 'disbursed');

        $this->expectException(LogicException::class);
        $this->unscoped(PlatformFee::class)->sole()->delete();
    }
}
