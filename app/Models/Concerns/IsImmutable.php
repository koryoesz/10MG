<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Append-only records (ledger, audit trail): once written they can never be changed or removed.
 */
trait IsImmutable
{
    public static function bootIsImmutable(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' records are immutable.'));
        static::deleting(fn () => throw new LogicException(static::class.' records are immutable.'));
    }
}
