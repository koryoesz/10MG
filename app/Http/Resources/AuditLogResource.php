<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution_id' => $this->institution_id,
            'action' => $this->action,
            'actor' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->user->role,
            ] : null),
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at,
        ];
    }
}
