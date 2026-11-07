<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterInstitutionRequest;
use App\Http\Resources\InstitutionResource;
use App\Http\Resources\UserResource;
use App\Services\InstitutionService;
use Illuminate\Http\JsonResponse;

/**
 * @tags Onboarding
 */
class InstitutionRegistrationController extends Controller
{
    public function __construct(private InstitutionService $institutions) {}

    /**
     * Register a lender.
     *
     * Creates the institution (status `pending`), its first `institution_admin` user and default
     * branding. A platform operator must approve it before it can be used.
     *
     * @unauthenticated
     */
    public function store(RegisterInstitutionRequest $request): JsonResponse
    {
        $registration = $this->institutions->register($request->validated('institution'), $request->validated('admin'));

        return response()->json([
            'data' => [
                'institution' => new InstitutionResource($registration['institution']),
                'admin' => new UserResource($registration['admin']),
            ],
            'message' => 'Institution registered and awaiting platform approval.',
        ], 201);
    }
}
