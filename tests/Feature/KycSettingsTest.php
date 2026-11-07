<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Institution;
use App\Models\InstitutionKycSetting;
use App\Models\LoanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each lender chooses its own KYC policy: require it or not, and which checks to run.
 */
class KycSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $lender;

    private Customer $pharmacy;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->lender = Institution::factory()->create();
        $this->pharmacy = Customer::factory()->forInstitution($this->lender)->kycPending()->create();
        $this->product = LoanProduct::factory()->forInstitution($this->lender)->create();
    }

    public function test_a_lender_without_settings_gets_the_platform_defaults(): void
    {
        $this->unscoped(InstitutionKycSetting::class)->where('institution_id', $this->lender->id)->delete();

        $this->asAdmin()->getJson('/api/v1/kyc-settings')
            ->assertOk()
            ->assertJsonPath('data.kyc_required', true)
            ->assertJsonPath('data.enabled_checks', ['bvn', 'nin'])
            ->assertJsonPath('data.phone_match_required', false);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/tenant', ['X-Tenant' => $this->lender->slug])
            ->assertJsonPath('data.kyc', ['required_before_applying' => true, 'checks' => ['bvn', 'nin']]);

        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['nin_check_enabled' => false])->assertOk();
        $this->assertSame(1, $this->unscoped(InstitutionKycSetting::class)->where('institution_id', $this->lender->id)->count());
    }

    public function test_a_newly_registered_lender_gets_a_settings_row(): void
    {
        $this->postJson('/api/v1/institutions', [
            'institution' => ['name' => 'Ikeja MFB', 'slug' => 'ikeja-mfb', 'email' => 'hello@ikeja.test'],
            'admin' => ['name' => 'Admin', 'email' => 'ceo@ikeja.test', 'password' => 'Password123'],
        ])->assertCreated();

        $this->assertSame(1, $this->unscoped(InstitutionKycSetting::class)
            ->whereRelation('institution', 'slug', 'ikeja-mfb')->count());
    }

    public function test_turning_kyc_off_lets_unverified_borrowers_apply(): void
    {
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/loans', $this->application())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'KYC_REQUIRED')
            ->assertJsonPath('error.details.outstanding_checks', ['bvn', 'nin']);

        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['kyc_required' => false])
            ->assertOk()
            ->assertJsonPath('data.kyc_required', false);

        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/loans', $this->application())->assertCreated();
    }

    public function test_switching_every_check_off_makes_kyc_not_required(): void
    {
        $this->asAdmin()->patchJson('/api/v1/kyc-settings', [
            'kyc_required' => false, 'bvn_check_enabled' => false, 'nin_check_enabled' => false,
        ])->assertOk()->assertJsonPath('data.enabled_checks', []);

        $this->as($this->pharmacy->user)->getJson('/api/v1/me/kyc')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_required')
            ->assertJsonPath('data.can_apply', true);

        $this->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertConflict()
            ->assertJsonPath('error.code', 'KYC_NOT_ENABLED');
    }

    public function test_requiring_kyc_with_no_checks_enabled_is_refused(): void
    {
        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['bvn_check_enabled' => false, 'nin_check_enabled' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kyc_required'], 'error.details');

        $this->getJson('/api/v1/kyc-settings')->assertJsonPath('data.enabled_checks', ['bvn', 'nin']);
    }

    public function test_bvn_only_lender_never_calls_nimc_and_does_not_ask_for_a_nin(): void
    {
        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['nin_check_enabled' => false])->assertOk();
        $this->liveProviders(bvnPhoneMatches: true);

        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity(['nin' => null]))
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.checks_passed', ['bvn'])
            ->assertJsonPath('data.owner.nin', null);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'nimc'));
        $this->idempotentPost('/api/v1/loans', $this->application())->assertCreated();
    }

    public function test_enabling_a_check_later_makes_it_outstanding_until_the_borrower_verifies_again(): void
    {
        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['nin_check_enabled' => false])->assertOk();
        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();

        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['nin_check_enabled' => true])->assertOk();

        $this->as($this->pharmacy->user)->getJson('/api/v1/me/kyc')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checks_passed', ['bvn'])
            ->assertJsonPath('data.outstanding_checks', ['nin'])
            ->assertJsonPath('data.can_apply', false);

        $this->idempotentPost('/api/v1/loans', $this->application())
            ->assertUnprocessable()
            ->assertJsonPath('error.details.outstanding_checks', ['nin']);

        $this->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertOk()
            ->assertJsonPath('data.checks_passed', ['bvn', 'nin']);

        $this->idempotentPost('/api/v1/loans', $this->application())->assertCreated();
    }

    public function test_phone_matching_makes_a_stale_bvn_phone_fail(): void
    {
        $this->liveProviders(bvnPhoneMatches: false);

        $this->as($this->pharmacy->user)->idempotentPost('/api/v1/me/kyc', $this->identity())->assertOk();

        $other = Customer::factory()->forInstitution($this->lender)->kycPending()->create();
        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['phone_match_required' => true])->assertOk();

        $this->as($other->user)->idempotentPost('/api/v1/me/kyc', $this->identity(['bvn' => '12345678902', 'nin' => '98765434322']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'KYC_VERIFICATION_FAILED')
            ->assertJsonPath('error.details.bvn.failed_fields', ['phone']);
    }

    public function test_settings_are_per_lender(): void
    {
        $otherLender = Institution::factory()->create();
        $otherBorrower = Customer::factory()->forInstitution($otherLender)->kycPending()->create();
        $otherProduct = LoanProduct::factory()->forInstitution($otherLender)->create();

        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['kyc_required' => false])->assertOk();

        $this->as($otherBorrower->user)->idempotentPost('/api/v1/loans', $this->application($otherProduct))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'KYC_REQUIRED');
    }

    public function test_only_the_lender_admin_can_change_settings_and_changes_are_audited(): void
    {
        $this->as($this->staff($this->lender, Role::LoanOfficer))->patchJson('/api/v1/kyc-settings', ['kyc_required' => false])->assertForbidden();
        $this->as($this->pharmacy->user)->getJson('/api/v1/kyc-settings')->assertForbidden();

        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['bvn_check_enabled' => false])->assertOk();

        $entry = AuditLog::query()->where('action', 'institution.kyc_settings_updated')->sole();
        $this->assertSame(['bvn_check_enabled' => false], $entry->new_values);
    }

    public function test_the_storefront_tells_borrowers_which_checks_apply(): void
    {
        $this->asAdmin()->patchJson('/api/v1/kyc-settings', ['nin_check_enabled' => false])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/tenant', ['X-Tenant' => $this->lender->slug])
            ->assertOk()
            ->assertJsonPath('data.kyc', ['required_before_applying' => true, 'checks' => ['bvn']]);
    }

    private function asAdmin(): static
    {
        return $this->as($this->staff($this->lender, Role::InstitutionAdmin));
    }

    /**
     * Live mode against faked NIBSS / NIMC: names and DOB match; the BVN phone as given.
     */
    private function liveProviders(bool $bvnPhoneMatches): void
    {
        config(['services.nibss.mode' => 'live', 'services.nimc.mode' => 'live']);
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok']),
            'api.nibss-plc.com.ng/bvnr/*' => Http::response(['responseCode' => '00', 'responseMessage' => 'Successful', 'reference' => 'NIBSS-1',
                'data' => ['fieldMatches' => ['firstName' => true, 'lastName' => true, 'dateOfBirth' => true, 'phoneNumber' => $bvnPhoneMatches]]]),
            'api.nimc.gov.ng/api/*' => Http::response(['responseCode' => '00', 'responseMessage' => 'Successful', 'reference' => 'NIMC-1',
                'data' => ['fieldMatches' => ['firstname' => true, 'surname' => true, 'birthdate' => true, 'telephoneno' => true]]]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function application(?LoanProduct $product = null): array
    {
        return ['loan_product_id' => ($product ?? $this->product)->id, 'amount' => 20_000_000, 'tenor' => 4, 'purpose' => 'inventory_restock'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function identity(array $overrides = []): array
    {
        return array_filter($overrides + [
            'first_name' => 'Adaeze', 'last_name' => 'Okafor', 'date_of_birth' => '1984-06-21',
            'phone' => '+2348031234567', 'bvn' => '12345678901', 'nin' => '98765434321',
        ], fn (mixed $value): bool => $value !== null);
    }
}
