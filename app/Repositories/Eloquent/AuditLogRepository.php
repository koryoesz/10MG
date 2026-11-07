<?php

namespace App\Repositories\Eloquent;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AuditLogRepository implements AuditLogRepositoryInterface
{
    public function create(array $attributes): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill($attributes)->save();

        return $log;
    }

    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user')
            ->when($filters['institution_id'] ?? null, fn (Builder $query, string $id) => $query->where('institution_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['auditable_id'] ?? null, fn (Builder $query, string $id) => $query->where('auditable_id', $id))
            ->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }
}
