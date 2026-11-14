<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssignSuperAdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function run_migration(): void
    {
        (require database_path('migrations/2026_11_18_000001_assign_super_admin_role.php'))->up();
    }

    public function test_the_oldest_active_admin_becomes_super_admin_and_sees_the_whole_menu(): void
    {
        AdminUser::factory()->create(['is_active' => false]);
        $first = AdminUser::factory()->create();
        $second = AdminUser::factory()->create();

        $this->run_migration();
        $this->run_migration(); // idempotente

        $this->assertTrue($first->fresh()->hasRole('super-admin'));
        $this->assertFalse($second->fresh()->hasRole('super-admin'));
        $this->assertSame(1, \DB::table('model_has_roles')->count());

        $this->actingAs($first->fresh(), 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.screens.index', 'coupon-batches'))->assertSee(route('admin.screens.index', 'admin-users'));
    }

    public function test_it_does_nothing_when_a_super_admin_already_exists(): void
    {
        $holder = AdminUser::factory()->create();
        $holder->assignRole(Role::findOrCreate('super-admin', 'admin'));
        $other = AdminUser::factory()->create();

        $this->run_migration();

        $this->assertFalse($other->fresh()->hasRole('super-admin'));
    }

    public function test_the_configured_email_wins(): void
    {
        AdminUser::factory()->create();
        $me = AdminUser::factory()->create(['email' => 'yo@example.com']);
        config(['admin.bootstrap_super_admin_email' => 'YO@example.com']);

        $this->run_migration();

        $this->assertTrue($me->fresh()->hasRole('super-admin'));
        $this->assertSame(1, \DB::table('model_has_roles')->count());
    }
}
