<?php

namespace App\Repositories\Eloquent;

use App\Enums\IdempotencyStatus;
use App\Models\IdempotencyKey;
use App\Repositories\Contracts\IdempotencyKeyRepositoryInterface;

class IdempotencyKeyRepository implements IdempotencyKeyRepositoryInterface
{
    public function find(string $userId, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()->where('user_id', $userId)->where('idempotency_key', $key)->first();
    }

    public function create(array $attributes): IdempotencyKey
    {
        $record = new IdempotencyKey;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function complete(IdempotencyKey $record, int $status, string $body): void
    {
        $record->forceFill([
            'status' => IdempotencyStatus::Completed,
            'response_status' => $status,
            'response_body' => $body,
        ])->save();
    }

    public function delete(IdempotencyKey $record): void
    {
        $record->delete();
    }
}
