<?php

namespace App\Services;

use App\Enums\KycStatus;
use App\Exceptions\Api\KycAlreadyVerified;
use App\Exceptions\Api\KycNotEnabled;
use App\Exceptions\Api\KycVerificationFailed;
use App\Models\Customer;
use App\Models\InstitutionKycSetting;
use App\Models\User;
use App\Repositories\Contracts\CustomerDirectorRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\Identity\BvnService;
use App\Services\Identity\IdentityCheck;
use App\Services\Identity\IdentityQuery;
use App\Services\Identity\NinService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Know-your-customer for a borrowing business, following the lender's own KYC settings:
 * the owner's BVN is matched with NIBSS and/or their NIN with NIMC, depending on which
 * checks the lender has switched on.
 *
 * Provider calls run outside the database transaction so no row lock is held while waiting
 * on the network. The outcome (pass or fail) is always persisted and audited; only masked
 * numbers and provider references are ever written to the audit log.
 */
class KycService
{
    public function __construct(
        private CustomerRepositoryInterface $customers,
        private CustomerDirectorRepositoryInterface $directors,
        private KycSettingsService $settings,
        private BvnService $bvn,
        private NinService $nin,
        private AuditLogger $audit,
    ) {}

    /**
     * The business with its owner and the lender's settings attached (`kycSettings`).
     */
    public function statusOf(User $owner): Customer
    {
        return $this->customers->findForUser($owner)->load('owner')->setRelation('kycSettings', $this->settings->current());
    }

    /**
     * @param  array{first_name: string, middle_name?: string|null, last_name: string, date_of_birth: string, phone: string, bvn?: string, nin?: string}  $data
     *
     * @throws KycNotEnabled
     * @throws KycAlreadyVerified
     * @throws KycVerificationFailed
     * @throws ValidationException
     */
    public function submit(User $owner, array $data): Customer
    {
        $settings = $this->settings->current();

        if ($settings->enabledChecks() === []) {
            throw new KycNotEnabled;
        }

        $customer = $this->customers->findForUser($owner);

        if ($customer->outstandingKycChecks($settings) === []) {
            throw new KycAlreadyVerified;
        }

        $this->ensureNotLinkedElsewhere($customer, $data, $settings);

        $checks = $this->runChecks($data, $settings);

        $customer = DB::transaction(fn (): Customer => $this->record($customer, $data, $checks, $settings));

        if ($customer->kyc_status !== KycStatus::Verified) {
            throw new KycVerificationFailed(details: array_map(fn (IdentityCheck $check): array => $check->toArray(), $checks));
        }

        return $customer->setRelation('kycSettings', $settings);
    }

    /**
     * Every enabled check runs with the same submitted details, so all passed checks describe one person.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, IdentityCheck>
     */
    private function runChecks(array $data, InstitutionKycSetting $settings): array
    {
        $person = new IdentityQuery(
            number: '',
            firstName: $data['first_name'],
            lastName: $data['last_name'],
            dateOfBirth: CarbonImmutable::parse($data['date_of_birth']),
            phone: $data['phone'],
            middleName: $data['middle_name'] ?? null,
        );

        $checks = [];

        if ($settings->bvn_check_enabled) {
            $checks['bvn'] = $this->bvn->verify($person->withNumber($data['bvn']), $settings->phone_match_required);
        }

        if ($settings->nin_check_enabled) {
            $checks['nin'] = $this->nin->verify($person->withNumber($data['nin']), $settings->phone_match_required);
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, IdentityCheck>  $checks
     */
    private function record(Customer $customer, array $data, array $checks, InstitutionKycSetting $settings): Customer
    {
        $customer = $this->customers->lockForUpdate($customer);

        // A concurrent submission may have verified the business while this one waited for the providers.
        if ($customer->outstandingKycChecks($settings) === []) {
            throw new KycAlreadyVerified;
        }

        $now = now();
        $director = $this->directors->ownerFor($customer)->fill([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'date_of_birth' => $data['date_of_birth'],
            'phone' => $data['phone'],
            'verification_checks' => array_map(fn (IdentityCheck $check): array => $check->toArray(), $checks),
        ]);

        foreach ($checks as $type => $check) {
            $director->setIdentityNumber($type, $data[$type]);
            $director->setAttribute("{$type}_reference", $check->reference);
            $director->setAttribute("{$type}_verified_at", $check->verified ? $now : null);
        }

        $this->directors->save($director);

        // Checks the lender has switched off keep their earlier result; the ones just run are replaced.
        $passed = array_keys(array_filter($checks, fn (IdentityCheck $check): bool => $check->verified));
        $customer->kyc_checks = array_values(array_unique([
            ...array_diff($customer->kyc_checks ?? [], array_keys($checks)),
            ...$passed,
        ]));

        $verified = $customer->outstandingKycChecks($settings) === [];
        $customer->kyc_status = $verified ? KycStatus::Verified : KycStatus::Failed;
        $customer->kyc_verified_at = $verified ? $now : null;
        $this->customers->save($customer);

        $audit = ['owner' => $director->fullName(), 'checks_run' => array_keys($checks)];
        foreach ($checks as $type => $check) {
            $audit[$type] = $this->mask($director->getAttribute("{$type}_last4"));
            $audit["{$type}_reference"] = $check->reference;
            $audit["{$type}_verified"] = $check->verified;
        }
        $this->audit->record($verified ? 'customer.kyc_verified' : 'customer.kyc_failed', $customer, newValues: $audit);

        return $customer->setRelation('owner', $director);
    }

    /**
     * One person's BVN / NIN may back only one business per lender.
     *
     * @param  array<string, mixed>  $data
     */
    private function ensureNotLinkedElsewhere(Customer $customer, array $data, InstitutionKycSetting $settings): void
    {
        $errors = [];

        foreach ($settings->enabledChecks() as $type) {
            if ($this->directors->isLinkedToAnotherCustomer($type, $data[$type], $customer)) {
                $errors[$type] = ['This '.strtoupper($type).' is already linked to another business.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function mask(string $lastFour): string
    {
        return str_repeat('*', 7).$lastFour;
    }
}
