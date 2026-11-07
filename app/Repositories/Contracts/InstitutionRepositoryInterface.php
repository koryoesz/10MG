<?php

namespace App\Repositories\Contracts;

use App\Enums\InstitutionStatus;
use App\Models\Institution;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

interface InstitutionRepositoryInterface
{
    /**
     * @param  array{status?: string}  $filters
     * @return LengthAwarePaginator<int, Institution>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator;

    public function findBySlug(string $slug): ?Institution;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, InstitutionStatus $status): Institution;

    public function lockForUpdate(Institution $institution): Institution;

    public function save(Institution $institution): Institution;

    public function revokeUserTokens(Institution $institution): void;

    /**
     * @return LazyCollection<int, Institution>
     */
    public function all(): LazyCollection;
}
