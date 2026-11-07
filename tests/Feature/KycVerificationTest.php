<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerDirector;
use App\Models\Institution;
use App\Models\LoanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KycVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Customer $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->institution = Institution::factory()->create();
        $this->pharmacy = Customer::factory()->forInstitution($this->institution)->kycPending()->create();
    }

    public function test_owner_bvn_and_nin_are_verified_and_returned_masked(): void
    {
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.owner.name', 'Adaeze Okafor')
            ->assertJsonPath('data.owner.bvn.masked', '*******8901')
            ->assertJsonPath('data.owner.bvn.verified', true)
            ->assertJsonPath('data.owner.nin.masked', '*******4321')
            ->assertJsonPath('data.owner.nin.verified', true)
            ->assertJsonMissingPath('data.owner.bvn.number');

        $this->assertSame(KycStatus::Verified, $this->pharmacy->fresh()->kyc_status);
        $this->assertStringStartsWith('NIBSS-SBX-', $this->unscoped(CustomerDirector::class)->sole()->bvn_reference);
    }

    public function test_identity_numbers_are_encrypted_at_rest_and_never_audited_in_clear(): void
    {
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();

        $row = DB::table('customer_directors')->sole();
        $this->assertStringNotContainsString('12345678901', $row->bvn);
        $this->assertSame(CustomerDirector::fingerprint('12345678901'), $row->bvn_hash);
        $this->assertSame('12345678901', $this->unscoped(CustomerDirector::class)->sole()->bvn);

        $entry = AuditLog::query()->where('action', 'customer.kyc_verified')->sole();
        $this->assertSame('*******8901', $entry->new_values['bvn']);
        $this->assertStringNotContainsString('12345678901', json_encode($entry->new_values));
        $this->assertStringNotContainsString('98765434321', json_encode($entry->new_values));
    }

    public function test_loan_application_is_blocked_until_kyc_passes(): void
    {
        $product = LoanProduct::factory()->forInstitution($this->institution)->create();
        $application = ['loan_product_id' => $product->id, 'amount' => 20_000_000, 'tenor' => 4, 'purpose' => 'inventory_restock'];

        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/loans', $application)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'KYC_REQUIRED')
            ->assertJsonPath('error.details.kyc_status', 'pending');

        $this->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();

        $this->idempotentPost('/api/v1/loans', $application)->assertCreated();
    }

    public function test_a_mismatch_reported_by_nibss_fails_kyc_and_can_be_retried(): void
    {
        config(['services.nibss.mode' => 'live', 'services.nimc.mode' => 'live']);
        Http::fake([
            'api.nibss-plc.com.ng/oauth/token' => Http::response(['access_token' => 'nibss-token']),
            'api.nimc.gov.ng/oauth/token' => Http::response(['access_token' => 'nimc-token']),
            'api.nibss-plc.com.ng/bvnr/*' => Http::sequence()
                ->push($this->providerResponse(['firstName' => true, 'lastName' => true, 'dateOfBirth' => false, 'phoneNumber' => false]))
                ->push($this->providerResponse(['firstName' => true, 'lastName' => true, 'dateOfBirth' => true, 'phoneNumber' => false])),
            'api.nimc.gov.ng/api/*' => Http::response($this->providerResponse(['firstname' => true, 'surname' => true, 'birthdate' => true])),
        ]);

        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity(['date_of_birth' => '1985-03-12']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'KYC_VERIFICATION_FAILED')
            ->assertJsonPath('error.details.bvn.failed_fields', ['date_of_birth'])
            ->assertJsonPath('error.details.nin.verified', true);

        $this->assertSame(KycStatus::Failed, $this->pharmacy->fresh()->kyc_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'customer.kyc_failed')->count());

        // Corrected date of birth: name and DOB match, a stale phone number is tolerated.
        $this->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->assertSame(1, DB::table('customer_directors')->count());
    }

    public function test_provider_outage_returns_503_and_stores_nothing(): void
    {
        config(['services.nibss.mode' => 'live']);
        Http::fake(['api.nibss-plc.com.ng/*' => Http::response(['message' => 'Service unavailable'], 503)]);

        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'IDENTITY_PROVIDER_UNAVAILABLE')
            ->assertJsonPath('error.details.provider', 'nibss');

        $this->assertSame(0, DB::table('customer_directors')->count());
        $this->assertSame(KycStatus::Pending, $this->pharmacy->fresh()->kyc_status);
    }

    public function test_a_verified_business_cannot_resubmit(): void
    {
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();

        $this->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertConflict()
            ->assertJsonPath('error.code', 'KYC_ALREADY_VERIFIED');
    }

    public function test_one_persons_bvn_backs_only_one_business_per_lender(): void
    {
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();

        $sameLender = Customer::factory()->forInstitution($this->institution)->kycPending()->create();
        $this->as($sameLender->user)->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bvn', 'nin'], 'error.details');

        $otherLender = Customer::factory()->forInstitution(Institution::factory()->create())->kycPending()->create();
        $this->as($otherLender->user)->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();
    }

    public function test_identity_numbers_are_validated(): void
    {
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity([
            'bvn' => '1234',
            'nin' => 'ABCDEFGHIJK',
            'date_of_birth' => now()->subYears(10)->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bvn', 'nin', 'date_of_birth'], 'error.details');

        $this->assertSame(0, DB::table('customer_directors')->count());
    }

    public function test_kyc_status_is_visible_to_staff_and_the_borrower(): void
    {
        $this->as($this->pharmacy->user)->getJson('/api/v1/me/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.owner', null);

        $this->as($this->staff($this->institution, Role::LoanOfficer))
            ->getJson("/api/v1/customers/{$this->pharmacy->id}")
            ->assertOk()
            ->assertJsonPath('data.kyc.status', 'pending');
    }

    public function test_a_newly_registered_business_reports_kyc_pending(): void
    {
        $this->postJson('/api/v1/tenant/customers', [
            'contact_name' => 'Chioma Eze', 'email' => 'chioma@eze.test', 'password' => 'Password123',
            'business_name' => 'Eze Pharmacy', 'business_type' => 'pharmacy', 'registration_number' => 'RC7654321',
            'licence_number' => 'PCN/1', 'licence_expires_at' => now()->addYear()->toDateString(), 'year_established' => 2018,
            'phone' => '+2348035550101', 'city' => 'Ikeja', 'state' => 'Lagos',
            'monthly_revenue' => 250_000_000, 'monthly_expenses' => 180_000_000,
        ], ['X-Tenant' => $this->institution->slug])
            ->assertCreated()
            ->assertJsonPath('data.customer.kyc.status', 'pending')
            ->assertJsonPath('data.customer.kyc.verified_at', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function identity(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Adaeze',
            'last_name' => 'Okafor',
            'date_of_birth' => '1984-06-21',
            'phone' => '+2348031234567',
            'bvn' => '12345678901',
            'nin' => '98765434321',
        ];
    }

    /**
     * @param  array<string, bool>  $fieldMatches
     * @return array<string, mixed>
     */
    private function providerResponse(array $fieldMatches): array
    {
        return [
            'responseCode' => '00',
            'responseMessage' => 'Successful',
            'reference' => 'REF-'.uniqid(),
            'data' => ['fieldMatches' => $fieldMatches],
        ];
    }
}
