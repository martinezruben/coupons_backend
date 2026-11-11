<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\License;
use Illuminate\Database\Eloquent\Factories\Factory;

class LicenseFactory extends Factory
{
    protected $model = License::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
            'status' => 'ACTIVE',
        ];
    }
}
