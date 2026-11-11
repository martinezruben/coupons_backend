<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Location;
use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class SyncLogFactory extends Factory
{
    protected $model = SyncLog::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'device_id' => Device::factory(),
            'operation' => $this->faker->randomElement(['PUSH', 'PULL']),
            'entity' => 'transactions',
            'records_count' => 0,
            'status' => 'SUCCESS',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
