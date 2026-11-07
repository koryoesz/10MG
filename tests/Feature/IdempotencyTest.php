<?php

namespace Tests\Feature;

use App\Contracts\DisbursementGateway;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Institution;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanTransaction;
use App\Services\Disbursement\DisbursementResult;
use App\Services\Disbursement\MockDisbursementGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private LoanProduct $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->product = LoanProduct::factory()->forInstitution($this->institution)->create();
        $this->customer = $this->customer($this->institution);
    }

    public function test_mutating_loan_endpoints_require_an_idempotency_key_and_return_400(): void
    {
        $this->as($this->customer->user)
            ->postJson('/api/v1/loans', $this->application())
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, $this->unscoped(Loan::class)->count());
    }

    public function test_retry_with_the_same_key_replays_the_original_response_without_side_effects(): void
    {
        $this->as($this->customer->user);

        $first = $this->idempotentPost('/api/v1/loans', $this->application(), 'apply-001')->assertCreated();
        $retry = $this->idempotentPost('/api/v1/loans', $this->application(), 'apply-001');

        $retry->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $first->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(1, $this->unscoped(Loan::class)->count());
    }

    public function test_retried_repayment_is_applied_only_once(): void
    {
        $loan = $this->loanFor($this->customer, $this->product, 'disbursed');
        $this->as($this->staff($this->institution, Role::FinanceOfficer));

        foreach (range(1, 3) as $attempt) {
            $this->idempotentPost("/api/v1/loans/{$loan->id}/repayments", ['amount' => 250_000, 'channel' => 'card'], 'repay-xyz')->assertCreated();
        }

        $this->assertSame(250_000, $this->unscoped(Loan::class)->find($loan->id)->amount_paid);
        $this->assertSame(1, $this->unscoped(LoanTransaction::class)->where('type', 'repayment')->count());
    }

    public function test_reusing_a_key_with_a_different_payload_returns_422(): void
    {
        $this->as($this->customer->user);
        $this->idempotentPost('/api/v1/loans', $this->application(), 'apply-002')->assertCreated();

        $this->idempotentPost('/api/v1/loans', ['amount' => 9_000_000] + $this->application(), 'apply-002')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_payload_key_order_does_not_affect_the_fingerprint(): void
    {
        $this->as($this->customer->user);
        $this->idempotentPost('/api/v1/loans', $this->application(), 'apply-003')->assertCreated();

        $this->idempotentPost('/api/v1/loans', array_reverse($this->application(), true), 'apply-003')
            ->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $other = $this->customer($this->institution);

        $this->as($this->customer->user)->idempotentPost('/api/v1/loans', $this->application(), 'shared-key')->assertCreated();
        $this->as($other->user)->idempotentPost('/api/v1/loans', $this->application(), 'shared-key')
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->unscoped(Loan::class)->count());
    }

    public function test_business_errors_are_stored_and_replayed(): void
    {
        $this->as($this->customer->user);
        $tooBig = ['amount' => 999_999_999_999] + $this->application();

        $this->idempotentPost('/api/v1/loans', $tooBig, 'apply-004')->assertUnprocessable();
        $this->idempotentPost('/api/v1/loans', $tooBig, 'apply-004')
            ->assertUnprocessable()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('error.code', 'LOAN_TERMS_VIOLATION');
    }

    public function test_concurrent_request_with_the_same_key_returns_409(): void
    {
        $this->as($this->customer->user);
        $lock = Cache::lock('idempotency:'.$this->customer->user_id.':'.hash('sha256', 'in-flight'), 30);
        $lock->get();

        $this->idempotentPost('/api/v1/loans', $this->application(), 'in-flight')
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS');

        $lock->release();
        $this->assertSame(0, $this->unscoped(Loan::class)->count());
    }

    public function test_server_errors_are_not_stored_so_the_client_can_retry(): void
    {
        $loan = $this->loanFor($this->customer, $this->product, 'approved');
        $finance = $this->staff($this->institution, Role::FinanceOfficer);

        // A rail that times out once, then recovers: a transient outage.
        $this->app->instance(DisbursementGateway::class, new class implements DisbursementGateway
        {
            private int $calls = 0;

            public function disburse(Loan $loan, int $amount, string $idempotencyReference): DisbursementResult
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('Gateway timeout');
                }

                return (new MockDisbursementGateway)->disburse($loan, $amount, $idempotencyReference);
            }
        });

        $this->as($finance)->idempotentPost("/api/v1/loans/{$loan->id}/disburse", [], 'disburse-1')->assertServerError();
        $this->assertFalse(IdempotencyKey::query()->where('idempotency_key', 'disburse-1')->exists());

        $this->as($finance)->idempotentPost("/api/v1/loans/{$loan->id}/disburse", [], 'disburse-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'disbursed');
    }

    /**
     * @return array<string, mixed>
     */
    private function application(): array
    {
        return ['purpose' => 'inventory_restock', 'loan_product_id' => $this->product->id, 'amount' => 5_000_000, 'tenor' => 3];
    }
}
