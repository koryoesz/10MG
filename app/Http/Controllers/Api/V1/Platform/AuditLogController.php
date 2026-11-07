<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Platform administration
 */
class AuditLogController extends Controller
{
    /**
     * Platform-wide audit trail.
     */
    public function index(ListAuditLogsRequest $request, AuditLogService $auditLogs): AnonymousResourceCollection
    {
        return AuditLogResource::collection($auditLogs->forPlatform($request->validated()));
    }
}
