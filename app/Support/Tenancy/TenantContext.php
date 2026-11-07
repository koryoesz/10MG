<?php

namespace App\Support\Tenancy;

use App\Exceptions\Api\TenantContextMissing;
use App\Models\Institution;

/**
 * Request-scoped holder of the institution (tenant) the current request acts for.
 *
 * Registered as a scoped singleton, so it is reset between requests and queue jobs.
 */
class TenantContext
{
    private ?Institution $institution = null;

    public function set(Institution $institution): void
    {
        $this->institution = $institution;
    }

    public function forget(): void
    {
        $this->institution = null;
    }

    public function has(): bool
    {
        return $this->institution !== null;
    }

    public function get(): ?Institution
    {
        return $this->institution;
    }

    public function id(): ?string
    {
        return $this->institution?->getKey();
    }

    /**
     * @throws TenantContextMissing
     */
    public function require(): Institution
    {
        return $this->institution ?? throw new TenantContextMissing;
    }

    /**
     * Run a callback while acting for the given institution, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  callable(Institution): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Institution $institution, callable $callback): mixed
    {
        $previous = $this->institution;
        $this->institution = $institution;

        try {
            return $callback($institution);
        } finally {
            $this->institution = $previous;
        }
    }
}
