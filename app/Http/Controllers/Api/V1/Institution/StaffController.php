<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Http\Resources\UserResource;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Staff & access control
 */
class StaffController extends Controller
{
    public function __construct(private StaffService $staff) {}

    /**
     * List staff.
     */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->staff->list());
    }

    /**
     * Create a staff member.
     *
     * Roles: `institution_admin`, `loan_officer` (reviews loans), `finance_officer` (disburses & records repayments).
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        return (new UserResource($this->staff->create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Update a staff member's role or active status.
     *
     * Deactivating a user revokes all of their tokens. Administrators cannot deactivate or re-role themselves.
     */
    public function update(UpdateStaffRequest $request, string $staff): UserResource
    {
        return new UserResource($this->staff->update($request->user(), $staff, $request->validated()));
    }
}
