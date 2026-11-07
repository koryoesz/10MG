<?php

namespace App\Http\Controllers\Api\V1\Institution;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBrandingRequest;
use App\Http\Resources\BrandingResource;
use App\Services\BrandingService;

/**
 * @tags White-label configuration
 */
class BrandingController extends Controller
{
    public function __construct(private BrandingService $branding) {}

    /**
     * Show branding.
     */
    public function show(): BrandingResource
    {
        return new BrandingResource($this->branding->current());
    }

    /**
     * Update branding.
     *
     * Partial update: only the fields sent are changed.
     */
    public function update(UpdateBrandingRequest $request): BrandingResource
    {
        return new BrandingResource($this->branding->update($request->validated()));
    }
}
