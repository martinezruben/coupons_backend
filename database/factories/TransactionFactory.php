<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Location;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'external_id' => $this->faker->unique()->numberBetween(1000000, 9999999),
            'location_id' => Location::factory(),
            'device_id' => Device::factory(),
            'user_id' => User::factory(),
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => $this->faker->randomFloat(2, 10, 500),
            'occurred_at' => $this->faker->dateTime(),
            'is_synced' => true,
        ];
    }
}
