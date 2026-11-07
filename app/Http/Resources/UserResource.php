<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'permissions' => array_map(fn ($permission) => $permission->value, $this->role->permissions()),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'created_at' => $this->created_at,
        ];
    }
}
