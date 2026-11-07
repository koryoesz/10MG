<?php

namespace App\Enums;

/**
 * Fine-grained abilities. Each case is registered as a Gate in AppServiceProvider,
 * so routes can declare `->can(Permission::LoansApprove->value)`.
 */
enum Permission: string
{
    case InstitutionsManage = 'institutions.manage';
    case BrandingManage = 'branding.manage';
    case KycSettingsManage = 'kyc-settings.manage';
    case ProductsManage = 'products.manage';
    case StaffManage = 'staff.manage';
    case AuditView = 'audit.view';
    case PlatformFeesView = 'platform-fees.view';
    case LoansView = 'loans.view';
    case LoansReview = 'loans.review';
    case LoansDisburse = 'loans.disburse';
    case RepaymentsRecord = 'repayments.record';
    case LoansApply = 'loans.apply';
    case LoansViewOwn = 'loans.view-own';
    case RepaymentsMakeOwn = 'repayments.make-own';
}
