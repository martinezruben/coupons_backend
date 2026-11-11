<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyCouponsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Base "legacy" simulada: SQLite en memoria como segunda conexión.
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('legacy');

        $legacy = Schema::connection('legacy');
        $legacy->create('coupons', function (Blueprint $t) {
            $t->bigIncrements('coupon_id');
            $t->string('coupon_number');
            $t->string('coupon_token')->nullable();
            $t->boolean('coupon_active')->default(1);
            $t->date('coupon_date')->nullable();
            $t->date('coupon_expire_date')->nullable();
            $t->decimal('coupon_amount', 12, 2);
            $t->decimal('coupon_balance', 12, 2);
        });
        $legacy->create('coupons_codbar', function (Blueprint $t) {
            $t->bigIncrements('coupon_id');
            $t->string('coupon_number');
            $t->boolean('coupon_active')->default(1);
            $t->date('coupon_expire_date')->nullable();
            $t->decimal('coupon_amount', 12, 2);
            $t->decimal('coupon_balance', 12, 2);
        });
        $legacy->create('transactions', function (Blueprint $t) {
            $t->bigIncrements('transaction_id');
            $t->unsignedBigInteger('coupon_id');
            $t->string('coupon_number');
            $t->decimal('transaction_amount', 12, 2);
            $t->integer('device_id')->default(1);
            $t->integer('site_id')->default(1);
            $t->string('coupon_type');
            $t->timestamps();
        });
        $legacy->create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->integer('admin')->default(0);
            $t->timestamps();
        });

        $db = DB::connection('legacy');
        $db->table('coupons')->insert([
            ['coupon_id' => 7, 'coupon_number' => 'Q1', 'coupon_token' => 't1', 'coupon_active' => 1, 'coupon_amount' => '100.00', 'coupon_balance' => '40.00'],
            ['coupon_id' => 9, 'coupon_number' => 'Q2', 'coupon_token' => 't2', 'coupon_active' => 0, 'coupon_amount' => '50.00', 'coupon_balance' => '50.00'],
        ]);
        $db->table('coupons_codbar')->insert([
            ['coupon_id' => 3, 'coupon_number' => 'B1', 'coupon_active' => 1, 'coupon_amount' => '30.00', 'coupon_balance' => '30.00'],
        ]);
        $db->table('transactions')->insert([
            ['transaction_id' => 11, 'coupon_id' => 7, 'coupon_number' => 'Q1', 'transaction_amount' => '60.00', 'device_id' => 5, 'site_id' => 2, 'coupon_type' => 'QRCODE', 'created_at' => '2025-08-01 10:00:00', 'updated_at' => '2025-08-01 10:00:00'],
        ]);
        $db->table('users')->insert([
            ['id' => 1, 'name' => 'Ana Admin', 'email' => 'Ana@Example.com', 'password' => Hash::make('Secreta-123!'), 'admin' => 1],
            ['id' => 2, 'name' => 'Caja Uno', 'email' => 'caja@example.com', 'password' => Hash::make('Otra-clave-1!'), 'admin' => 0],
        ]);
    }

    public function test_imports_coupons_transactions_and_users_keeping_ids(): void
    {
        $this->artisan('coupons:import-legacy')->assertSuccessful();

        $this->assertDatabaseHas('coupons', ['coupon_id' => 7, 'coupon_number' => 'Q1', 'coupon_token' => 't1']);
        $this->assertDatabaseHas('coupons', ['coupon_id' => 9, 'coupon_active' => 0]);
        $this->assertDatabaseHas('coupons_codbar', ['coupon_id' => 3, 'coupon_number' => 'B1']);
        $this->assertDatabaseHas('coupon_transactions', [
            'transaction_id' => 11, 'coupon_id' => 7, 'legacy_device_id' => 5, 'legacy_site_id' => 2, 'user_id' => null,
        ]);

        $admin = User::where('username', 'ana@example.com')->first();
        $this->assertSame('ADMIN', $admin->role);
        $this->assertSame(1, (int) $admin->legacy_user_id);
        $this->assertSame('CASHIER', User::where('username', 'caja@example.com')->value('role'));
    }

    public function test_imported_users_can_log_in_with_their_old_password(): void
    {
        $this->artisan('coupons:import-legacy')->assertSuccessful();

        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'Secreta-123!'])
            ->assertOk()->assertJsonPath('user.id', 1)->assertJsonPath('user.admin', 1);
    }

    public function test_is_idempotent_and_does_not_overwrite_balances_unless_asked(): void
    {
        $this->artisan('coupons:import-legacy')->assertSuccessful();
        DB::table('coupons')->where('coupon_id', 7)->update(['coupon_balance' => '10.00']);

        $this->artisan('coupons:import-legacy')->assertSuccessful();
        $this->assertDatabaseCount('coupons', 2);
        $this->assertDatabaseCount('coupon_transactions', 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('10.00', number_format((float) DB::table('coupons')->where('coupon_id', 7)->value('coupon_balance'), 2, '.', ''));

        $this->artisan('coupons:import-legacy', ['--sync-balances' => true])->assertSuccessful();
        $this->assertSame('40.00', number_format((float) DB::table('coupons')->where('coupon_id', 7)->value('coupon_balance'), 2, '.', ''));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('coupons:import-legacy', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('coupons', 0);
        $this->assertDatabaseCount('coupon_transactions', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_to_use_the_default_connection_as_source(): void
    {
        $this->artisan('coupons:import-legacy', ['--connection' => config('database.default')])->assertFailed();
    }
}
