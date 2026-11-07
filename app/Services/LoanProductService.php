<?php

namespace App\Services;

use App\Models\LoanProduct;
use App\Models\LoanProductFee;
use App\Repositories\Contracts\LoanProductRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * White-label product configuration. Editing a product never changes existing loans,
 * which keep a snapshot of the terms they were priced with.
 */
class LoanProductService
{
    public function __construct(
        private LoanProductRepositoryInterface $products,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{is_active?: bool}  $filters
     * @return Collection<int, LoanProduct>
     */
    public function list(array $filters = []): Collection
    {
        return $this->products->all($filters);
    }

    /**
     * Products shown on the public storefront.
     *
     * @return Collection<int, LoanProduct>
     */
    public function catalogue(): Collection
    {
        return $this->products->all(['is_active' => true]);
    }

    public function show(LoanProduct $product): LoanProduct
    {
        return $this->products->withFees($product);
    }

    /**
     * @param  array<string, mixed>  $attributes  Validated product data, optionally with `fees`.
     */
    public function create(array $attributes): LoanProduct
    {
        return DB::transaction(function () use ($attributes): LoanProduct {
            $fees = $attributes['fees'] ?? [];
            unset($attributes['fees']);

            $product = $this->products->create($attributes, $this->tenant->require()->currency);

            foreach ($fees as $fee) {
                $this->products->addFee($product, $fee);
            }

            $this->audit->record('loan_product.created', $product, newValues: $attributes + ['fees' => $fees]);

            return $this->products->withFees($product);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LoanProduct $product, array $attributes): LoanProduct
    {
        return DB::transaction(function () use ($product, $attributes): LoanProduct {
            $this->products->fill($product, $attributes);
            $this->audit->recordChanges('loan_product.updated', $product);

            return $this->products->withFees($this->products->save($product));
        });
    }

    /**
     * Soft-delete the product; existing loans are unaffected.
     */
    public function archive(LoanProduct $product): void
    {
        DB::transaction(function () use ($product): void {
            $this->products->archive($product);
            $this->audit->record('loan_product.archived', $product, ['code' => $product->code]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addFee(LoanProduct $product, array $attributes): LoanProductFee
    {
        return DB::transaction(function () use ($product, $attributes): LoanProductFee {
            $fee = $this->products->addFee($product, $attributes);
            $this->audit->record('loan_product.fee_added', $product, newValues: $attributes);

            return $fee;
        });
    }

    public function removeFee(LoanProduct $product, LoanProductFee $fee): void
    {
        DB::transaction(function () use ($product, $fee): void {
            $this->products->removeFee($fee);
            $this->audit->record('loan_product.fee_removed', $product, ['fee' => $fee->name, 'value' => $fee->value]);
        });
    }
}
