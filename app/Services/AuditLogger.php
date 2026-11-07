<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Writes append-only audit entries. Call it inside the same DB transaction as the change
 * it describes, so the audit row commits or rolls back together with it.
 */
class AuditLogger
{
    public function __construct(
        private TenantContext $tenant,
        private Request $request,
        private AuditLogRepositoryInterface $auditLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->request->user();

        return $this->auditLogs->create([
            'institution_id' => $this->institutionIdFor($subject),
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 512) ?: null,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * Record only the attributes that changed on a dirty model (call before saving it).
     */
    public function recordChanges(string $action, Model $subject): ?AuditLog
    {
        $changed = array_diff_key($subject->getDirty(), array_flip(['updated_at']));

        if ($changed === []) {
            return null;
        }

        $old = array_intersect_key($subject->getRawOriginal(), $changed);

        return $this->record($action, $subject, $this->normalise($old), $this->normalise($changed));
    }

    private function institutionIdFor(?Model $subject): ?string
    {
        if ($subject instanceof Institution) {
            return $subject->getKey();
        }

        return $subject?->getAttribute('institution_id') ?? $this->tenant->id();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value, $values);
    }
}
