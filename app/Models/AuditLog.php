<?php

namespace App\Models;

use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail. Not globally tenant-scoped because platform-level events
 * have no institution; tenant filtering is applied explicitly when listing.
 */
#[WithoutTimestamps]
class AuditLog extends Model
{
    use IsImmutable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
