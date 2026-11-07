<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuditLogService
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogs,
        private TenantContext $tenant,
    ) {}

    /**
     * The current institution's trail. The tenant filter always wins over any requested one.
     *
     * @param  array{action?: string, auditable_id?: string}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function forCurrentInstitution(array $filters): LengthAwarePaginator
    {
        return $this->auditLogs->paginate(['institution_id' => $this->tenant->require()->id] + $filters);
    }

    /**
     * The platform-wide trail for operators.
     *
     * @param  array{institution_id?: string, action?: string, auditable_id?: string}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function forPlatform(array $filters): LengthAwarePaginator
    {
        return $this->auditLogs->paginate($filters);
    }
}
