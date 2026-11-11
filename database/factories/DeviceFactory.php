<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'device_fingerprint' => $this->faker->unique()->uuid(),
            'name' => $this->faker->word(),
            'is_enabled' => true,
        ];
    }
}
