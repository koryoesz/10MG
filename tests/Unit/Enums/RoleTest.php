<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

/**
 * The complete role → permission matrix. A failure names the exact grant that changed.
 */
class RoleTest extends TestCase
{
    public function test_role_permission_matrix(): void
    {
        $expected = [
            'platform_admin' => ['institutions.manage', 'audit.view'],
            'institution_admin' => [
                'branding.manage', 'kyc-settings.manage', 'products.manage', 'staff.manage', 'audit.view', 'platform-fees.view',
                'loans.view', 'loans.review', 'loans.disburse', 'repayments.record',
            ],
            'loan_officer' => ['loans.view', 'loans.review'],
            'finance_officer' => ['platform-fees.view', 'loans.view', 'loans.disburse', 'repayments.record'],
            'customer' => ['loans.apply', 'loans.view-own', 'repayments.make-own'],
        ];

        foreach (Role::cases() as $role) {
            $this->assertEqualsCanonicalizing(
                $expected[$role->value],
                array_map(fn (Permission $permission) => $permission->value, $role->permissions()),
                "Unexpected permissions for {$role->value}",
            );
        }
    }

    public function test_loan_officer_cannot_disburse_and_finance_officer_cannot_approve(): void
    {
        $this->assertFalse(Role::LoanOfficer->hasPermission(Permission::LoansDisburse));
        $this->assertFalse(Role::FinanceOfficer->hasPermission(Permission::LoansReview));
    }
}
