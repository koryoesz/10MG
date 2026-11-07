<?php

namespace App\Repositories\Eloquent;

use App\Enums\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\NewAccessToken;

/**
 * Users are not globally tenant-scoped (login must find them before a tenant is known),
 * so every staff query here filters on institution_id explicitly.
 */
class UserRepository implements UserRepositoryInterface
{
    public function findForLogin(string $email, ?string $institutionId): ?User
    {
        return User::query()
            ->where('email', $email)
            ->when(
                $institutionId,
                fn (Builder $query, string $id) => $query->where('institution_id', $id),
                fn (Builder $query) => $query->whereNull('institution_id'),
            )
            ->first();
    }

    public function create(array $attributes, ?string $institutionId, Role $role): User
    {
        $user = new User($attributes);
        $user->institution_id = $institutionId;
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function save(User $user): User
    {
        $user->save();

        return $user;
    }

    public function staffOf(string $institutionId): Collection
    {
        return $this->staffQuery($institutionId)->orderBy('name')->orderBy('id')->get();
    }

    public function findStaffOrFail(string $institutionId, string $userId): User
    {
        return $this->staffQuery($institutionId)->findOrFail($userId);
    }

    public function issueToken(User $user, string $name, CarbonInterface $expiresAt): NewAccessToken
    {
        return $user->createToken($name, ['*'], $expiresAt);
    }

    public function revokeTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /**
     * @return Builder<User>
     */
    private function staffQuery(string $institutionId): Builder
    {
        return User::query()
            ->where('institution_id', $institutionId)
            ->whereIn('role', Role::assignableStaffRoles());
    }
}
