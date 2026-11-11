<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionItemFactory extends Factory
{
    protected $model = TransactionItem::class;

    public function definition(): array
    {
        $product = Product::factory();
        $qty = $this->faker->randomFloat(2, 1, 10);
        $unitPrice = $this->faker->randomFloat(2, 5, 100);
        $lineTotal = $qty * $unitPrice;

        return [
            'transaction_id' => Transaction::factory(),
            'product_id' => $product,
            'product_name' => 'Test Product',
            'product_sku' => 'TEST-001',
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'tax' => $lineTotal * 0.18,
            'line_total' => $lineTotal,
        ];
    }
}
