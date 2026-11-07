<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Audit
 */
class AuditLogController extends Controller
{
    /**
     * Institution audit trail.
     *
     * Filter by `action` (e.g. `loan.approved`) or by the subject (`auditable_id`).
     */
    public function index(ListAuditLogsRequest $request, AuditLogService $auditLogs): AnonymousResourceCollection
    {
        return AuditLogResource::collection($auditLogs->forCurrentInstitution($request->safe()->except('institution_id')));
    }
}
