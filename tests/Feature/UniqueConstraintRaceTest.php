<?php

namespace Tests\Feature;

use App\Contracts\BvnRegistry;
use App\Enums\KycStatus;
use App\Enums\Role;
use App\Exceptions\ApiErrorRenderer;
use App\Models\Customer;
use App\Models\CustomerDirector;
use App\Models\Institution;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Identity\IdentityQuery;
use App\Services\Identity\ProviderMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Two concurrent requests can both pass a `unique` validation rule before either inserts.
 * These tests reproduce that interleaving deterministically: a model `creating` hook inserts
 * the competing row after validation has passed but before the request's own insert.
 * The loser must get the same 422 a sequential duplicate gets: never a 500 or leaked SQL.
 */
class UniqueConstraintRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_racing_borrower_sign_up_returns_422_not_500(): void
    {
        $institution = Institution::factory()->create();
        $this->raceOnce(User::class, fn () => DB::table('users')->insert([
            'id' => (string) Str::ulid(), 'institution_id' => $institution->id, 'name' => 'Rival',
            'email' => 'race@pharmacy.test', 'role' => Role::Customer->value, 'is_active' => true,
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->postJson('/api/v1/tenant/customers', $this->signUp(), ['X-Tenant' => $institution->slug])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.email.0', 'The email has already been taken.')
            ->assertJsonMissingPath('error.details.sql');

        $this->assertSame(0, Customer::query()->withoutGlobalScopes()->count(), 'the losing sign-up must roll back completely');
    }

    public function test_racing_lender_registration_reports_the_nested_request_field(): void
    {
        $this->raceOnce(Institution::class, fn () => Institution::factory()->create(['slug' => 'race-bank']));

        $this->postJson('/api/v1/institutions', [
            'institution' => ['name' => 'Race Bank', 'slug' => 'race-bank', 'email' => 'hello@race.test'],
            'admin' => ['name' => 'Admin', 'email' => 'admin@race.test', 'password' => 'Secret123'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details', ['institution.slug' => ['The slug has already been taken.']]);
    }

    public function test_racing_product_code_returns_422(): void
    {
        $institution = Institution::factory()->create();
        $this->raceOnce(LoanProduct::class, fn () => LoanProduct::factory()->forInstitution($institution)->create(['code' => 'RACE']));

        $this->as($this->staff($institution, Role::InstitutionAdmin))->postJson('/api/v1/loan-products', [
            'name' => 'Race', 'code' => 'RACE', 'min_amount' => 1_000_000, 'max_amount' => 9_000_000,
            'min_tenor' => 1, 'max_tenor' => 3, 'interest_rate_bps' => 2400,
            'interest_method' => 'flat', 'repayment_frequency' => 'monthly',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.code.0', 'The code has already been taken.');
    }

    public function test_racing_kyc_with_the_same_bvn_reports_the_bvn_field(): void
    {
        $institution = Institution::factory()->create();
        $rival = Customer::factory()->forInstitution($institution)->create();
        $borrower = Customer::factory()->forInstitution($institution)->kycPending()->create();
        $this->raceOnce(CustomerDirector::class, fn () => CustomerDirector::factory()->forCustomer($rival)->make()
            ->setIdentityNumber('bvn', '12345678901')->setIdentityNumber('nin', '55555555555')->save());

        $this->as($borrower->user)->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertUnprocessable()
            ->assertJsonPath('error.details', ['bvn' => ['This BVN is already linked to another business.']]);

        $this->assertSame(KycStatus::Pending, $borrower->fresh()->kyc_status, 'the losing submission must roll back');
    }

    public function test_concurrent_kyc_submission_for_an_already_verified_business_is_refused(): void
    {
        $borrower = Customer::factory()->forInstitution(Institution::factory()->create())->kycPending()->create();

        // The rival request verifies the business while this one is waiting on NIBSS / NIMC.
        $this->app->bind(BvnRegistry::class, fn () => new class($borrower) implements BvnRegistry
        {
            public function __construct(private Customer $borrower) {}

            public function matchBvn(IdentityQuery $query): ProviderMatch
            {
                DB::table('customers')->where('id', $this->borrower->id)->update(['kyc_status' => KycStatus::Verified->value, 'kyc_checks' => json_encode(['bvn', 'nin'])]);

                return new ProviderMatch('nibss', true, ['first_name' => true, 'last_name' => true, 'date_of_birth' => true], 'REF', '00', 'Successful');
            }
        });

        $this->as($borrower->user)->idempotentPost('/api/v1/me/kyc', $this->identity())
            ->assertConflict()
            ->assertJsonPath('error.code', 'KYC_ALREADY_VERIFIED');

        $this->assertSame(0, DB::table('customer_directors')->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function driverMessages(): array
    {
        return [
            'mysql' => ["Duplicate entry 'x-a@b.test' for key 'users.users_institution_id_email_unique'", 'email'],
            'mysql, unprefixed key' => ["Duplicate entry 'race' for key 'institutions_slug_unique'", 'slug'],
            'postgres' => ['duplicate key value violates unique constraint "users_institution_id_email_unique" DETAIL: Key (institution_id, email)=(x, y) already exists.', 'email'],
            'sqlite' => ['UNIQUE constraint failed: customers.institution_id, customers.registration_number', 'registration_number'],
            'mysql, bvn fingerprint' => ["Duplicate entry 'x' for key 'customer_directors.customer_directors_institution_id_bvn_hash_unique'", 'bvn'],
            'sqlite, nin fingerprint' => ['UNIQUE constraint failed: customer_directors.institution_id, customer_directors.nin_hash', 'nin'],
        ];
    }

    #[DataProvider('driverMessages')]
    public function test_duplicate_column_is_recognised_for_every_supported_database(string $driverMessage, string $field): void
    {
        $response = (new ApiErrorRenderer)($this->violation($driverMessage), Request::create('/'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([$field], array_keys((array) $response->getData(true)['error']['details']));
    }

    public function test_internal_unique_columns_are_not_disclosed(): void
    {
        $response = (new ApiErrorRenderer)(
            $this->violation("Duplicate entry 'x' for key 'idempotency_keys.idempotency_keys_user_id_idempotency_key_unique'"),
            Request::create('/'),
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('DUPLICATE_RESOURCE', $response->getData(true)['error']['code']);
        $this->assertStringNotContainsString('idempotency', $response->getContent());
    }

    /**
     * Run $competitor once, just before the next model of $model is inserted.
     *
     * @param  class-string<Model>  $model
     */
    private function raceOnce(string $model, callable $competitor): void
    {
        $raced = false;

        $model::creating(function () use (&$raced, $competitor): void {
            if (! $raced) {
                $raced = true;
                $competitor();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function identity(): array
    {
        return [
            'first_name' => 'Adaeze', 'last_name' => 'Okafor', 'date_of_birth' => '1984-06-21',
            'phone' => '+2348031234567', 'bvn' => '12345678901', 'nin' => '98765434321',
        ];
    }

    private function violation(string $driverMessage): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException('testing', 'insert into …', [], new PDOException($driverMessage));
    }

    /**
     * @return array<string, mixed>
     */
    private function signUp(): array
    {
        return [
            'contact_name' => 'Racer', 'email' => 'race@pharmacy.test', 'password' => 'Secret123',
            'business_name' => 'Race Pharmacy', 'business_type' => 'pharmacy', 'registration_number' => 'RC1234567',
            'licence_number' => 'PCN/1', 'licence_expires_at' => now()->addYear()->toDateString(),
            'year_established' => 2015, 'phone' => '+2348000000099', 'city' => 'Ikeja', 'state' => 'Lagos',
            'monthly_revenue' => 300_000_000, 'monthly_expenses' => 200_000_000,
        ];
    }
}
