<?php

namespace App\Providers;

use App\Contracts\BvnRegistry;
use App\Contracts\CreditBureau;
use App\Contracts\DisbursementGateway;
use App\Contracts\NinRegistry;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\CustomerDirector;
use App\Models\Institution;
use App\Models\InstitutionBranding;
use App\Models\InstitutionKycSetting;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanProductFee;
use App\Models\User;
use App\Services\Credit\MockHealthcareCreditScorer;
use App\Services\Disbursement\MockDisbursementGateway;
use App\Services\Identity\NibssService;
use App\Services\Identity\NimcService;
use App\Support\OpenApi\DocumentRequestHeaders;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(CreditBureau::class, MockHealthcareCreditScorer::class);
        $this->app->bind(DisbursementGateway::class, MockDisbursementGateway::class);
        $this->app->bind(BvnRegistry::class, NibssService::class);
        $this->app->bind(NinRegistry::class, NimcService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'institution' => Institution::class,
            'institution_branding' => InstitutionBranding::class,
            'institution_kyc_setting' => InstitutionKycSetting::class,
            'user' => User::class,
            'customer' => Customer::class,
            'customer_director' => CustomerDirector::class,
            'loan' => Loan::class,
            'loan_product' => LoanProduct::class,
            'loan_product_fee' => LoanProductFee::class,
        ]);

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            })
            ->withOperationTransformers(DocumentRequestHeaders::class);
    }
}
