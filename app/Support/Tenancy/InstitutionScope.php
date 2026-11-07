<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the current institution.
 *
 * Fails closed: with no tenant in context the query matches nothing. Cross-tenant
 * system work (e.g. scheduled commands) must opt out explicitly with
 * `withoutGlobalScope(InstitutionScope::class)` or run inside `TenantContext::runAs()`.
 */
class InstitutionScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();
        $column = $model->qualifyColumn('institution_id');

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $tenantId);
    }
}
