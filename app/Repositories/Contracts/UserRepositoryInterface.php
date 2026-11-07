<?php

namespace App\Repositories\Contracts;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\NewAccessToken;

interface UserRepositoryInterface
{
    /**
     * Find a login: a tenant user when an institution is given, otherwise a platform operator.
     */
    public function findForLogin(string $email, ?string $institutionId): ?User;

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function create(array $attributes, ?string $institutionId, Role $role): User;

    public function save(User $user): User;

    /**
     * @return Collection<int, User>
     */
    public function staffOf(string $institutionId): Collection;

    public function findStaffOrFail(string $institutionId, string $userId): User;

    public function issueToken(User $user, string $name, CarbonInterface $expiresAt): NewAccessToken;

    public function revokeTokens(User $user): void;

    public function revokeCurrentToken(User $user): void;
}
