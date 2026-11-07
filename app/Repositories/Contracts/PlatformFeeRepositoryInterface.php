<?php

namespace App\Repositories\Contracts;

use App\Models\PlatformFee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PlatformFeeRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): PlatformFee;

    /**
     * Fees across every lender (platform operators), or the current tenant's only.
     *
     * @param  array{institution_id?: string, from?: string, to?: string}  $filters
     * @return LengthAwarePaginator<int, PlatformFee>
     */
    public function paginate(array $filters, bool $acrossTenants, int $perPage = 50): LengthAwarePaginator;

    /**
     * Totals for the same filters, per bearer.
     *
     * @param  array{institution_id?: string, from?: string, to?: string}  $filters
     * @return array{count: int, total_amount: int, by_bearer: array<string, array{count: int, amount: int}>}
     */
    public function summarise(array $filters, bool $acrossTenants): array;
}
