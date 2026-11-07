<?php

namespace App\Http\Middleware;

use App\Exceptions\Api\TenantContextMissing;
use App\Exceptions\Api\TenantInactive;
use App\Exceptions\Api\TenantMismatch;
use App\Exceptions\Api\TenantNotFound;
use App\Models\Institution;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes which institution the request acts for.
 *
 * - Authenticated tenant users: always their own institution (from the token, unspoofable).
 *   A conflicting `X-Tenant` header is rejected.
 * - Anonymous requests: the institution named by the `X-Tenant: <slug>` header.
 * - Platform operators have no tenant and cannot use tenant routes.
 *
 * Usage: `tenant` (a tenant is required) or `tenant:optional`.
 */
class ResolveTenant
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $this->tenant->forget();

        $institution = $this->resolve($request);

        if ($institution === null) {
            if ($mode !== 'optional') {
                throw new TenantContextMissing;
            }

            return $next($request);
        }

        if (! $institution->isActive()) {
            throw new TenantInactive(details: ['status' => $institution->status->value]);
        }

        $this->tenant->set($institution);
        Context::add('institution_id', $institution->id);

        return $next($request);
    }

    private function resolve(Request $request): ?Institution
    {
        $slug = $request->header('X-Tenant');
        $user = $request->user();

        if ($user?->institution_id !== null) {
            $institution = $user->institution;

            if (filled($slug) && $slug !== $institution->slug) {
                throw new TenantMismatch;
            }

            return $institution;
        }

        if ($user !== null || blank($slug)) {
            return null;
        }

        return Institution::query()->where('slug', $slug)->first() ?? throw new TenantNotFound;
    }
}
