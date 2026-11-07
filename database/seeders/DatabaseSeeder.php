<?php

namespace Database\Seeders;

use App\Enums\BusinessType;
use App\Enums\FeeCalculation;
use App\Enums\InterestMethod;
use App\Enums\LoanPurpose;
use App\Enums\PlatformFeeBearer;
use App\Enums\RepaymentFrequency;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerDirector;
use App\Models\Institution;
use App\Models\InstitutionBranding;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Loans\DisbursementService;
use App\Services\Loans\LoanApplicationService;
use App\Services\Loans\LoanDecisionService;
use App\Services\Loans\RepaymentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Demo data: a platform operator, two live lenders with distinct branding and healthcare loan
 * products, one lender awaiting approval, a pharmacy and a clinic as borrowers, and a pharmacy
 * inventory loan that has been through the full lifecycle.
 *
 * Every seeded account uses the password `Password123`.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'Password123';

    public function run(TenantContext $tenant): void
    {
        User::factory()->platformAdmin()->create([
            'name' => 'Platform Operator',
            'email' => 'operator@10mg.test',
            'password' => self::PASSWORD,
        ]);

        $lagos = $this->institution('Lagos Microfinance Bank', 'lagos-mfb', '#0B5FFF', '#FFB800');
        $abuja = $this->institution('Abuja Credit Union', 'abuja-credit', '#047857', '#F43F5E');
        // Lagos keeps the platform default (0.5%, lender-borne); Abuja passes a 1% fee to its borrowers.
        $abuja->forceFill(['platform_fee_bps' => 100, 'platform_fee_bearer' => PlatformFeeBearer::Borrower])->save();

        Institution::factory()->pending()->create([
            'name' => 'Kano Capital',
            'slug' => 'kano-capital',
            'email' => 'hello@kano-capital.test',
        ]);

        foreach ([$lagos, $abuja] as $institution) {
            $this->staff($institution);
        }

        $inventoryFinance = LoanProduct::factory()->forInstitution($lagos)->withStandardFees()->create([
            'name' => 'Pharmacy Inventory Finance',
            'code' => 'RX-STOCK',
            'description' => 'Restock fast-moving drugs and consumables; repaid monthly from sales.',
            'min_amount' => 10_000_000,
            'max_amount' => 500_000_000,
            'max_tenor' => 6,
            'interest_rate_bps' => 2400,
            'interest_method' => InterestMethod::ReducingBalance,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'eligible_business_types' => [BusinessType::Pharmacy->value, BusinessType::PatentMedicineStore->value],
        ]);

        LoanProduct::factory()->forInstitution($lagos)->create([
            'name' => 'Medical Equipment Loan',
            'code' => 'MED-EQUIP',
            'description' => 'Finance diagnostic machines, theatre equipment and lab analysers.',
            'min_amount' => 100_000_000,
            'max_amount' => 5_000_000_000,
            'min_tenor' => 6,
            'max_tenor' => 24,
            'interest_rate_bps' => 2800,
            'min_credit_score' => 620,
            'eligible_business_types' => [
                BusinessType::Clinic->value, BusinessType::Hospital->value,
                BusinessType::Laboratory->value, BusinessType::DiagnosticCentre->value,
            ],
        ]);

        LoanProduct::factory()->forInstitution($abuja)->flat()->create([
            'name' => 'Emergency Care Bridge',
            'code' => 'EMERGENCY',
            'description' => 'Same-day cash to keep emergency care running; repaid weekly.',
            'min_amount' => 5_000_000,
            'max_amount' => 200_000_000,
            'min_tenor' => 4,
            'max_tenor' => 12,
            'interest_rate_bps' => 3600,
            'repayment_frequency' => RepaymentFrequency::Weekly,
            'grace_period_days' => 1,
            'late_fee_type' => FeeCalculation::Fixed,
            'late_fee_value' => 100_000,
        ]);

        $okaforPharmacy = $this->business($lagos, 'Ada Okafor', 'ada@okaforpharmacy.test', [
            'business_name' => 'Okafor Family Pharmacy',
            'business_type' => BusinessType::Pharmacy,
            'licence_number' => 'PCN/PR/24518',
            'city' => 'Surulere',
            'state' => 'Lagos',
        ]);

        $this->business($abuja, 'Dr. Musa Bello', 'musa@belloclinic.test', [
            'business_name' => 'Bello Specialist Clinic',
            'business_type' => BusinessType::Clinic,
            'licence_number' => 'MDCN/FCT/0932',
            'city' => 'Wuse II',
            'state' => 'FCT',
            'monthly_revenue' => 800_000_000,
            'monthly_expenses' => 550_000_000,
        ]);

        $this->runSampleLoanLifecycle($tenant, $lagos, $okaforPharmacy, $inventoryFinance);
    }

    private function institution(string $name, string $slug, string $primary, string $secondary): Institution
    {
        $institution = Institution::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'email' => "hello@{$slug}.test",
        ]);

        InstitutionBranding::query()->withoutGlobalScopes()->where('institution_id', $institution->id)->update([
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'logo_url' => "https://cdn.10mg.test/{$slug}/logo.svg",
            'support_email' => "support@{$slug}.test",
            'custom_domain' => "loans.{$slug}.test",
        ]);

        return $institution;
    }

    private function staff(Institution $institution): void
    {
        foreach ([
            'admin' => Role::InstitutionAdmin,
            'officer' => Role::LoanOfficer,
            'finance' => Role::FinanceOfficer,
        ] as $handle => $role) {
            User::factory()->forInstitution($institution)->role($role)->create([
                'name' => ucfirst($handle).' — '.$institution->name,
                'email' => "{$handle}@{$institution->slug}.test",
                'password' => self::PASSWORD,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function business(Institution $institution, string $contactName, string $email, array $profile): Customer
    {
        $user = User::factory()->forInstitution($institution)->role(Role::Customer)->create([
            'name' => $contactName,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        $customer = Customer::factory()->forInstitution($institution)->create(['user_id' => $user->id] + $profile);

        [$firstName, $lastName] = explode(' ', str_replace('Dr. ', '', $contactName), 2);
        CustomerDirector::factory()->forCustomer($customer)->create(['first_name' => $firstName, 'last_name' => $lastName]);

        return $customer;
    }

    private function runSampleLoanLifecycle(TenantContext $tenant, Institution $institution, Customer $customer, LoanProduct $product): void
    {
        $tenant->runAs($institution, function (Institution $institution) use ($customer, $product): void {
            $officer = User::query()->where('email', "officer@{$institution->slug}.test")->firstOrFail();
            $finance = User::query()->where('email', "finance@{$institution->slug}.test")->firstOrFail();

            $loan = app(LoanApplicationService::class)->apply($customer, $product, 50_000_000, 6, LoanPurpose::InventoryRestock, 'Antimalarials and antibiotics ahead of rainy season');
            app(LoanDecisionService::class)->approve($loan, $officer, 'Six months of POS settlements reviewed.');
            $loan = app(DisbursementService::class)->disburse($loan, $finance);
            app(RepaymentService::class)->record($loan, $finance, $loan->installments()->first()->total_due, 'bank_transfer', 'SEED-PAY-0001');
        });
    }
}
