<?php

namespace Tests\Feature;

use App\Jobs\SendContingencyNotificationJob;
use App\Jobs\SendContingencyResolvedJob;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LocationContingencyObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_location_starts_contingency_once(): void
    {
        Queue::fake();
        $location = Location::factory()->create(['is_active' => false]);

        $location->update(['is_active' => true]);

        $this->assertNotNull($location->fresh()->contingency_started_at);
        Queue::assertPushed(SendContingencyNotificationJob::class, 1);
    }

    public function test_deactivating_a_location_resolves_contingency_once(): void
    {
        $location = Location::factory()->create(['is_active' => true, 'contingency_started_at' => now()]);
        Queue::fake();

        $location->update(['is_active' => false]);

        $this->assertNull($location->fresh()->contingency_started_at);
        Queue::assertPushed(SendContingencyResolvedJob::class, 1);
        Queue::assertNotPushed(SendContingencyNotificationJob::class);
    }
}
