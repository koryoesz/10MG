<?php

namespace App\Services;

use App\Enums\InstitutionStatus;
use App\Enums\Role;
use App\Exceptions\Api\InvalidInstitutionState;
use App\Models\Institution;
use App\Models\User;
use App\Repositories\Contracts\BrandingRepositoryInterface;
use App\Repositories\Contracts\InstitutionRepositoryInterface;
use App\Repositories\Contracts\KycSettingsRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Lender (tenant) onboarding and lifecycle.
 */
class InstitutionService
{
    public function __construct(
        private InstitutionRepositoryInterface $institutions,
        private BrandingRepositoryInterface $brandings,
        private KycSettingsRepositoryInterface $kycSettings,
        private UserRepositoryInterface $users,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * Onboard a lender with its first administrator, default branding and default KYC settings. It starts
     * `pending` until a platform operator activates it.
     *
     * @param  array<string, mixed>  $institutionData
     * @param  array{name: string, email: string, password: string}  $adminData
     * @return array{institution: Institution, admin: User}
     */
    public function register(array $institutionData, array $adminData): array
    {
        return DB::transaction(function () use ($institutionData, $adminData): array {
            $institution = $this->institutions->create($institutionData, InstitutionStatus::Pending);
            $admin = $this->users->create($adminData, $institution->id, Role::InstitutionAdmin);
            $this->brandings->createFor($institution, ['display_name' => $institution->name]);
            $this->kycSettings->createFor($institution);

            $this->audit->record('institution.registered', $institution, newValues: [
                'name' => $institution->name,
                'slug' => $institution->slug,
                'admin_email' => $admin->email,
            ], actor: $admin);

            return ['institution' => $institution->load('branding'), 'admin' => $admin];
        });
    }

    /**
     * @param  array{status?: string}  $filters
     * @return LengthAwarePaginator<int, Institution>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->institutions->paginate($filters);
    }

    /**
     * A lender as the platform operator sees it, including its branding and KYC policy.
     */
    public function show(Institution $institution): Institution
    {
        return $institution->load(['branding', 'kycSettings']);
    }

    /**
     * The current tenant's public profile for the white-label storefront.
     */
    public function storefrontProfile(): Institution
    {
        return $this->tenant->require()->load(['branding', 'kycSettings']);
    }

    /**
     * Approve a pending lender or reactivate a suspended one.
     */
    public function activate(Institution $institution, ?string $reason = null): Institution
    {
        return $this->changeStatus($institution, InstitutionStatus::Active, $reason);
    }

    /**
     * Block all tenant activity and revoke every token issued to the lender's users.
     */
    public function suspend(Institution $institution, ?string $reason = null): Institution
    {
        return $this->changeStatus($institution, InstitutionStatus::Suspended, $reason);
    }

    private function changeStatus(Institution $institution, InstitutionStatus $target, ?string $reason): Institution
    {
        return DB::transaction(function () use ($institution, $target, $reason): Institution {
            $institution = $this->institutions->lockForUpdate($institution);
            $previous = $institution->status;

            if ($previous === $target) {
                throw new InvalidInstitutionState("Institution is already {$target->value}.", ['status' => $previous->value]);
            }

            $institution->status = $target;

            if ($target === InstitutionStatus::Active) {
                $institution->approved_at ??= now();
                $institution->suspended_at = null;
            }

            if ($target === InstitutionStatus::Suspended) {
                $institution->suspended_at = now();
                $this->institutions->revokeUserTokens($institution);
            }

            $this->institutions->save($institution);

            $verb = $target === InstitutionStatus::Active ? 'activated' : 'suspended';
            $this->audit->record("institution.{$verb}", $institution,
                ['status' => $previous->value],
                array_filter(['status' => $target->value, 'reason' => $reason]),
            );

            return $institution;
        });
    }
}
