<?php

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class IdempotencyKey extends Model
{
    use Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
