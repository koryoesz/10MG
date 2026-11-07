<?php

namespace App\Repositories\Eloquent;

use App\Enums\PlatformFeeBearer;
use App\Models\PlatformFee;
use App\Repositories\Contracts\PlatformFeeRepositoryInterface;
use App\Support\Tenancy\InstitutionScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PlatformFeeRepository implements PlatformFeeRepositoryInterface
{
    public function record(array $attributes): PlatformFee
    {
        $fee = new PlatformFee;
        $fee->forceFill($attributes + ['created_at' => now()])->save();

        return $fee;
    }

    public function paginate(array $filters, bool $acrossTenants, int $perPage = 50): LengthAwarePaginator
    {
        return $this->filtered($filters, $acrossTenants)
            ->with('loan:id,reference')
            ->orderByDesc('accrued_at')
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    public function summarise(array $filters, bool $acrossTenants): array
    {
        $rows = $this->filtered($filters, $acrossTenants)
            ->selectRaw('bearer, COUNT(*) as fee_count, COALESCE(SUM(amount), 0) as fee_amount')
            ->groupBy('bearer')
            ->get();

        $byBearer = [];
        foreach (PlatformFeeBearer::cases() as $bearer) {
            $row = $rows->firstWhere('bearer', $bearer);
            $byBearer[$bearer->value] = ['count' => (int) ($row->fee_count ?? 0), 'amount' => (int) ($row->fee_amount ?? 0)];
        }

        return [
            'count' => array_sum(array_column($byBearer, 'count')),
            'total_amount' => array_sum(array_column($byBearer, 'amount')),
            'by_bearer' => $byBearer,
        ];
    }

    /**
     * @param  array{institution_id?: string, from?: string, to?: string}  $filters
     * @return Builder<PlatformFee>
     */
    private function filtered(array $filters, bool $acrossTenants): Builder
    {
        return PlatformFee::query()
            ->when($acrossTenants, fn (Builder $query) => $query->withoutGlobalScope(InstitutionScope::class))
            ->when($filters['institution_id'] ?? null, fn (Builder $query, string $id) => $query->where('institution_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('accrued_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('accrued_at', '<=', $to.' 23:59:59'));
    }
}
