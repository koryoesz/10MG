<?php

namespace App\Enums;

enum Role: string
{
    case PlatformAdmin = 'platform_admin';
    case InstitutionAdmin = 'institution_admin';
    case LoanOfficer = 'loan_officer';
    case FinanceOfficer = 'finance_officer';
    case Customer = 'customer';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformAdmin => [
                Permission::InstitutionsManage,
                Permission::AuditView,
            ],
            self::InstitutionAdmin => [
                Permission::BrandingManage,
                Permission::KycSettingsManage,
                Permission::ProductsManage,
                Permission::StaffManage,
                Permission::AuditView,
                Permission::PlatformFeesView,
                Permission::LoansView,
                Permission::LoansReview,
                Permission::LoansDisburse,
                Permission::RepaymentsRecord,
            ],
            self::LoanOfficer => [
                Permission::LoansView,
                Permission::LoansReview,
            ],
            self::FinanceOfficer => [
                Permission::PlatformFeesView,
                Permission::LoansView,
                Permission::LoansDisburse,
                Permission::RepaymentsRecord,
            ],
            self::Customer => [
                Permission::LoansApply,
                Permission::LoansViewOwn,
                Permission::RepaymentsMakeOwn,
            ],
        };
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::InstitutionAdmin, self::LoanOfficer, self::FinanceOfficer], true);
    }

    /**
     * Roles an institution admin is allowed to provision for their own tenant.
     *
     * @return list<self>
     */
    public static function assignableStaffRoles(): array
    {
        return [self::InstitutionAdmin, self::LoanOfficer, self::FinanceOfficer];
    }
}
