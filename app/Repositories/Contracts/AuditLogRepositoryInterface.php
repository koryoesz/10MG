<?php

namespace App\Repositories\Contracts;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AuditLog;

    /**
     * @param  array{institution_id?: string, action?: string, auditable_id?: string}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator;
}
