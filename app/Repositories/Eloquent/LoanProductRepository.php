<?php

namespace App\Repositories\Eloquent;

use App\Models\LoanProduct;
use App\Models\LoanProductFee;
use App\Repositories\Contracts\LoanProductRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LoanProductRepository implements LoanProductRepositoryInterface
{
    public function all(array $filters = []): Collection
    {
        return LoanProduct::query()
            ->with('fees')
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function findOrFail(string $id): LoanProduct
    {
        return LoanProduct::query()->findOrFail($id);
    }

    public function create(array $attributes, string $currency): LoanProduct
    {
        $product = new LoanProduct($attributes);
        $product->currency = $currency;
        $product->save();

        return $product;
    }

    public function fill(LoanProduct $product, array $attributes): LoanProduct
    {
        return $product->fill($attributes);
    }

    public function save(LoanProduct $product): LoanProduct
    {
        $product->save();

        return $product;
    }

    public function archive(LoanProduct $product): void
    {
        $product->delete();
    }

    public function addFee(LoanProduct $product, array $attributes): LoanProductFee
    {
        $fee = new LoanProductFee($attributes);
        $fee->institution_id = $product->institution_id;

        return $product->fees()->save($fee);
    }

    public function removeFee(LoanProductFee $fee): void
    {
        $fee->delete();
    }

    public function withFees(LoanProduct $product): LoanProduct
    {
        return $product->load('fees');
    }
}
