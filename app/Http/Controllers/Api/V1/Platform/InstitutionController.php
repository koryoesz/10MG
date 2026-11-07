<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeInstitutionStatusRequest;
use App\Http\Requests\ListInstitutionsRequest;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Services\InstitutionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Platform administration
 */
class InstitutionController extends Controller
{
    public function __construct(private InstitutionService $institutions) {}

    /**
     * List institutions.
     */
    public function index(ListInstitutionsRequest $request): AnonymousResourceCollection
    {
        return InstitutionResource::collection($this->institutions->paginate($request->validated()));
    }

    /**
     * Show an institution.
     */
    public function show(Institution $institution): InstitutionResource
    {
        return new InstitutionResource($this->institutions->show($institution));
    }

    /**
     * Activate an institution.
     *
     * Approves a pending institution or reactivates a suspended one.
     */
    public function activate(ChangeInstitutionStatusRequest $request, Institution $institution): InstitutionResource
    {
        return new InstitutionResource($this->institutions->activate($institution, $request->validated('reason')));
    }

    /**
     * Suspend an institution.
     *
     * Blocks all tenant activity and revokes every token issued to its users.
     */
    public function suspend(ChangeInstitutionStatusRequest $request, Institution $institution): InstitutionResource
    {
        return new InstitutionResource($this->institutions->suspend($institution, $request->validated('reason')));
    }
}
