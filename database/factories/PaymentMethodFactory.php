<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Cash', 'Credit Card', 'Debit Card', 'Check']),
            'is_active' => true,
        ];
    }
}
