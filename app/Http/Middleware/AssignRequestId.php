<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propagates (or generates) an `X-Request-Id` so a request can be traced across logs,
 * audit entries and the response.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id');

        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
