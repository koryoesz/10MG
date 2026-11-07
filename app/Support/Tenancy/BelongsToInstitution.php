<?php

namespace App\Support\Tenancy;

use App\Exceptions\Api\TenantContextMissing;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: queries are scoped to the current institution and
 * new records are stamped with it automatically.
 *
 * @mixin Model
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('institution_id') !== null) {
                return;
            }

            $tenantId = app(TenantContext::class)->id() ?? throw new TenantContextMissing;

            $model->setAttribute('institution_id', $tenantId);
        });
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
