<?php

namespace App\Providers;

use App\Repositories\Contracts;
use App\Repositories\Eloquent;
use Illuminate\Support\ServiceProvider;

/**
 * Binds each repository contract to its Eloquent implementation.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        Contracts\InstitutionRepositoryInterface::class => Eloquent\InstitutionRepository::class,
        Contracts\BrandingRepositoryInterface::class => Eloquent\BrandingRepository::class,
        Contracts\KycSettingsRepositoryInterface::class => Eloquent\KycSettingsRepository::class,
        Contracts\UserRepositoryInterface::class => Eloquent\UserRepository::class,
        Contracts\CustomerRepositoryInterface::class => Eloquent\CustomerRepository::class,
        Contracts\CustomerDirectorRepositoryInterface::class => Eloquent\CustomerDirectorRepository::class,
        Contracts\LoanProductRepositoryInterface::class => Eloquent\LoanProductRepository::class,
        Contracts\LoanRepositoryInterface::class => Eloquent\LoanRepository::class,
        Contracts\LoanInstallmentRepositoryInterface::class => Eloquent\LoanInstallmentRepository::class,
        Contracts\LoanTransactionRepositoryInterface::class => Eloquent\LoanTransactionRepository::class,
        Contracts\AuditLogRepositoryInterface::class => Eloquent\AuditLogRepository::class,
        Contracts\PlatformFeeRepositoryInterface::class => Eloquent\PlatformFeeRepository::class,
        Contracts\IdempotencyKeyRepositoryInterface::class => Eloquent\IdempotencyKeyRepository::class,
    ];
}
