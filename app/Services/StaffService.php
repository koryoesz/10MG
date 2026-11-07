<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return $this->users->staffOf($this->tenant->require()->id);
    }

    /**
     * @param  array{name: string, email: string, password: string, role: string}  $attributes
     */
    public function create(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = $this->users->create(
                ['name' => $attributes['name'], 'email' => $attributes['email'], 'password' => $attributes['password']],
                $this->tenant->require()->id,
                Role::from($attributes['role']),
            );

            $this->audit->record('staff.created', $user, newValues: ['email' => $user->email, 'role' => $user->role->value]);

            return $user;
        });
    }

    /**
     * Change a staff member's role or active status. Deactivation revokes all of their tokens.
     * Administrators cannot deactivate or re-role themselves.
     *
     * @param  array{role?: string, is_active?: bool}  $attributes
     *
     * @throws ValidationException
     */
    public function update(User $actor, string $staffId, array $attributes): User
    {
        $user = $this->users->findStaffOrFail($this->tenant->require()->id, $staffId);

        if ($user->is($actor)) {
            $this->guardSelfChange($user, $attributes);
        }

        return DB::transaction(function () use ($user, $attributes): User {
            if (array_key_exists('role', $attributes)) {
                $user->role = Role::from($attributes['role']);
            }

            if (array_key_exists('is_active', $attributes)) {
                $user->is_active = (bool) $attributes['is_active'];
            }

            $this->audit->recordChanges('staff.updated', $user);
            $this->users->save($user);

            if (! $user->is_active) {
                $this->users->revokeTokens($user);
            }

            return $user;
        });
    }

    /**
     * @param  array{role?: string, is_active?: bool}  $attributes
     */
    private function guardSelfChange(User $user, array $attributes): void
    {
        if (array_key_exists('is_active', $attributes) && ! $attributes['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
        }

        if (array_key_exists('role', $attributes) && $attributes['role'] !== $user->role->value) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role. Ask another administrator.']);
        }
    }
}
