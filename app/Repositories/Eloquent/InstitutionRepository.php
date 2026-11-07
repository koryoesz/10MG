<?php

namespace App\Repositories\Eloquent;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use App\Repositories\Contracts\InstitutionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

class InstitutionRepository implements InstitutionRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return Institution::query()
            ->with('branding')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    public function findBySlug(string $slug): ?Institution
    {
        return Institution::query()->where('slug', $slug)->first();
    }

    public function create(array $attributes, InstitutionStatus $status): Institution
    {
        $institution = new Institution($attributes);
        $institution->status = $status;
        $institution->save();

        return $institution;
    }

    public function lockForUpdate(Institution $institution): Institution
    {
        return Institution::query()->whereKey($institution->getKey())->lockForUpdate()->firstOrFail();
    }

    public function save(Institution $institution): Institution
    {
        $institution->save();

        return $institution;
    }

    public function revokeUserTokens(Institution $institution): void
    {
        $institution->users()->each(fn ($user) => $user->tokens()->delete());
    }

    public function all(): LazyCollection
    {
        return Institution::query()->lazyById();
    }
}
