<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Authentication
 */
class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    /**
     * Log in.
     *
     * Send `X-Tenant: <institution-slug>` to log in as that institution's staff or borrower.
     * Omit it to log in as a platform operator. Returns a bearer token valid for 30 days.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $session = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name', 'api'),
        );

        return response()->json([
            'data' => [
                'token' => $session['token'],
                'token_type' => 'Bearer',
                'expires_at' => $session['expires_at']->toIso8601String(),
                'user' => new UserResource($session['user']),
            ],
        ]);
    }

    /**
     * Current user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('customer'));
    }

    /**
     * Log out (revoke the current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json(['message' => 'Logged out.']);
    }
}
