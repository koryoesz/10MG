<?php

namespace App\Repositories\Contracts;

use App\Models\LoanProduct;
use App\Models\LoanProductFee;
use Illuminate\Database\Eloquent\Collection;

interface LoanProductRepositoryInterface
{
    /**
     * @param  array{is_active?: bool}  $filters
     * @return Collection<int, LoanProduct>
     */
    public function all(array $filters = []): Collection;

    public function findOrFail(string $id): LoanProduct;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, string $currency): LoanProduct;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(LoanProduct $product, array $attributes): LoanProduct;

    public function save(LoanProduct $product): LoanProduct;

    public function archive(LoanProduct $product): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addFee(LoanProduct $product, array $attributes): LoanProductFee;

    public function removeFee(LoanProductFee $fee): void;

    public function withFees(LoanProduct $product): LoanProduct;
}
