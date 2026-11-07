<?php

namespace App\Services;

use App\Exceptions\Api\AccountDisabled;
use App\Exceptions\Api\InvalidCredentials;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    private const TOKEN_LIFETIME_DAYS = 30;

    public function __construct(
        private UserRepositoryInterface $users,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * Log in as a user of the current tenant, or as a platform operator when there is none.
     *
     * @return array{token: string, expires_at: CarbonImmutable, user: User}
     *
     * @throws InvalidCredentials
     * @throws AccountDisabled
     */
    public function login(string $email, string $password, string $deviceName = 'api'): array
    {
        $user = $this->users->findForLogin($email, $this->tenant->id());

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentials;
        }

        if (! $user->is_active) {
            throw new AccountDisabled;
        }

        $user->last_login_at = now();
        $this->users->save($user);

        $expiresAt = CarbonImmutable::now()->addDays(self::TOKEN_LIFETIME_DAYS);
        $token = $this->users->issueToken($user, $deviceName, $expiresAt);

        $this->audit->record('auth.login', $user, actor: $user);

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt, 'user' => $user->load('customer')];
    }

    public function logout(User $user): void
    {
        $this->users->revokeCurrentToken($user);
    }

    public function issueToken(User $user): string
    {
        return $this->users->issueToken($user, 'api', CarbonImmutable::now()->addDays(self::TOKEN_LIFETIME_DAYS))->plainTextToken;
    }
}
