<?php

namespace App\Repositories\Contracts;

use App\Models\IdempotencyKey;

interface IdempotencyKeyRepositoryInterface
{
    public function find(string $userId, string $key): ?IdempotencyKey;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): IdempotencyKey;

    public function complete(IdempotencyKey $record, int $status, string $body): void;

    public function delete(IdempotencyKey $record): void;
}
