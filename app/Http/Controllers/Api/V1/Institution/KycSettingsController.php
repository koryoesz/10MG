<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateKycSettingsRequest;
use App\Http\Resources\KycSettingsResource;
use App\Services\KycSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * @tags White-label configuration
 */
class KycSettingsController extends Controller
{
    public function __construct(private KycSettingsService $settings) {}

    /**
     * Show KYC settings.
     *
     * The lender's identity-check policy. A lender that never changed it gets the platform defaults.
     */
    public function show(): KycSettingsResource
    {
        return new KycSettingsResource($this->settings->current());
    }

    /**
     * Update KYC settings.
     *
     * Turn the KYC requirement, the BVN check (NIBSS), the NIN check (NIMC) and phone matching on or
     * off. Partial update. Enabling a check later makes it outstanding for borrowers who have not
     * passed it; they must verify again before their next application.
     */
    public function update(UpdateKycSettingsRequest $request): JsonResponse
    {
        // Always 200: a lender's first change creates its settings row, but it is still an update.
        return (new KycSettingsResource($this->settings->update($request->validated())))->response()->setStatusCode(200);
    }
}
