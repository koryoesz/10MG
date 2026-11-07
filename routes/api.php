<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\CustomerRegistrationController;
use App\Http\Controllers\Api\V1\Institution\AuditLogController;
use App\Http\Controllers\Api\V1\Institution\BrandingController;
use App\Http\Controllers\Api\V1\Institution\CustomerController;
use App\Http\Controllers\Api\V1\Institution\KycSettingsController;
use App\Http\Controllers\Api\V1\Institution\LoanProductController;
use App\Http\Controllers\Api\V1\Institution\LoanProductFeeController;
use App\Http\Controllers\Api\V1\Institution\PlatformFeeController;
use App\Http\Controllers\Api\V1\Institution\StaffController;
use App\Http\Controllers\Api\V1\InstitutionRegistrationController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\Loans\LoanController;
use App\Http\Controllers\Api\V1\Loans\LoanDecisionController;
use App\Http\Controllers\Api\V1\Loans\LoanDisbursementController;
use App\Http\Controllers\Api\V1\Loans\LoanRepaymentController;
use App\Http\Controllers\Api\V1\Platform\AuditLogController as PlatformAuditLogController;
use App\Http\Controllers\Api\V1\Platform\InstitutionController as PlatformInstitutionController;
use App\Http\Controllers\Api\V1\Platform\PlatformFeeController as PlatformFeeLedgerController;
use App\Http\Controllers\Api\V1\TenantCatalogueController;
use App\Models\Loan;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {

    /*
    | Public
    */
    Route::post('institutions', [InstitutionRegistrationController::class, 'store'])
        ->middleware('throttle:auth')->name('institutions.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware(['tenant:optional', 'throttle:auth'])->name('auth.login');

    /*
    | Public, tenant-scoped via the X-Tenant header (white-label storefront)
    */
    Route::middleware('tenant')->prefix('tenant')->name('tenant.')->group(function (): void {
        Route::get('/', [TenantCatalogueController::class, 'show'])->name('show');
        Route::get('loan-products', [TenantCatalogueController::class, 'products'])->name('products');
        Route::post('loan-products/{loan_product}/quote', [TenantCatalogueController::class, 'quote'])->name('products.quote');
        Route::post('customers', [CustomerRegistrationController::class, 'store'])
            ->middleware('throttle:auth')->name('customers.register');
    });

    /*
    | Authenticated
    */
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        /*
        | Platform operators
        */
        Route::prefix('platform')->name('platform.')->can(Permission::InstitutionsManage->value)->group(function (): void {
            Route::get('institutions', [PlatformInstitutionController::class, 'index'])->name('institutions.index');
            Route::get('institutions/{institution}', [PlatformInstitutionController::class, 'show'])->name('institutions.show');
            Route::post('institutions/{institution}/activate', [PlatformInstitutionController::class, 'activate'])->name('institutions.activate');
            Route::post('institutions/{institution}/suspend', [PlatformInstitutionController::class, 'suspend'])->name('institutions.suspend');
            Route::patch('institutions/{institution}/platform-fee', [PlatformFeeLedgerController::class, 'update'])->name('institutions.platform-fee');
            Route::get('platform-fees', [PlatformFeeLedgerController::class, 'index'])->name('platform-fees.index');
            Route::get('audit-logs', [PlatformAuditLogController::class, 'index'])->name('audit-logs.index');
        });

        /*
        | Tenant users (institution staff and customers)
        */
        Route::middleware('tenant')->group(function (): void {

            // White-label configuration
            Route::get('branding', [BrandingController::class, 'show'])->can(Permission::BrandingManage->value)->name('branding.show');
            Route::patch('branding', [BrandingController::class, 'update'])->can(Permission::BrandingManage->value)->name('branding.update');
            Route::get('kyc-settings', [KycSettingsController::class, 'show'])->can(Permission::KycSettingsManage->value)->name('kyc-settings.show');
            Route::patch('kyc-settings', [KycSettingsController::class, 'update'])->can(Permission::KycSettingsManage->value)->name('kyc-settings.update');

            Route::middleware('can:'.Permission::ProductsManage->value)->group(function (): void {
                Route::apiResource('loan-products', LoanProductController::class);
                Route::post('loan-products/{loan_product}/fees', [LoanProductFeeController::class, 'store'])->name('loan-products.fees.store');
                Route::delete('loan-products/{loan_product}/fees/{fee}', [LoanProductFeeController::class, 'destroy'])
                    ->scopeBindings()->name('loan-products.fees.destroy');
            });

            // Staff & customers
            Route::middleware('can:'.Permission::StaffManage->value)->group(function (): void {
                Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
                Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
                Route::patch('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
            });

            Route::get('customers', [CustomerController::class, 'index'])->can(Permission::LoansView->value)->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])->can(Permission::LoansView->value)->name('customers.show');
            Route::get('customers/{customer}/loans', [CustomerController::class, 'loans'])->can(Permission::LoansView->value)->name('customers.loans');

            // Borrower self-service
            Route::middleware('can:'.Permission::LoansApply->value)->group(function (): void {
                Route::get('me/business', [BusinessProfileController::class, 'show'])->name('me.business.show');
                Route::patch('me/business', [BusinessProfileController::class, 'update'])->name('me.business.update');
                Route::get('me/kyc', [KycController::class, 'show'])->name('me.kyc.show');
                Route::post('me/kyc', [KycController::class, 'store'])->middleware('idempotent')->name('me.kyc.store');
            });

            Route::get('audit-logs', [AuditLogController::class, 'index'])->can(Permission::AuditView->value)->name('audit-logs.index');
            Route::get('platform-fees', [PlatformFeeController::class, 'index'])->can(Permission::PlatformFeesView->value)->name('platform-fees.index');

            // Lending workflow
            Route::get('loans', [LoanController::class, 'index'])->can('viewAny', Loan::class)->name('loans.index');
            Route::post('loans', [LoanController::class, 'store'])
                ->can(Permission::LoansApply->value)->middleware('idempotent')->name('loans.store');
            Route::get('loans/{loan}', [LoanController::class, 'show'])->can('view', 'loan')->name('loans.show');
            Route::get('loans/{loan}/schedule', [LoanController::class, 'schedule'])->can('view', 'loan')->name('loans.schedule');
            Route::get('loans/{loan}/transactions', [LoanController::class, 'transactions'])->can('view', 'loan')->name('loans.transactions');

            Route::middleware('idempotent')->group(function (): void {
                Route::post('loans/{loan}/approve', [LoanDecisionController::class, 'approve'])
                    ->can(Permission::LoansReview->value)->name('loans.approve');
                Route::post('loans/{loan}/reject', [LoanDecisionController::class, 'reject'])
                    ->can(Permission::LoansReview->value)->name('loans.reject');
                Route::post('loans/{loan}/cancel', [LoanDecisionController::class, 'cancel'])
                    ->can('cancel', 'loan')->name('loans.cancel');
                Route::post('loans/{loan}/disburse', [LoanDisbursementController::class, 'store'])
                    ->can(Permission::LoansDisburse->value)->name('loans.disburse');
                Route::post('loans/{loan}/repayments', [LoanRepaymentController::class, 'store'])
                    ->can('repay', 'loan')->name('loans.repayments.store');
            });
        });
    });
});
