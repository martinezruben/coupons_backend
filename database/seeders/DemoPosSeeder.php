<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Subfamily;
use App\Models\SyncLog;
use App\Models\SyncState;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Support\AdminRbac;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoPosSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('model_has_permissions')->delete();
        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        Permission::query()->delete();
        Role::query()->delete();
        AdminUser::query()->delete();

        TransactionPayment::query()->delete();
        TransactionItem::query()->delete();
        Transaction::query()->delete();
        Shift::query()->delete();
        SyncLog::query()->delete();
        SyncState::query()->delete();
        License::query()->delete();
        NcfSequence::query()->delete();

        Device::withTrashed()->forceDelete();
        Product::withTrashed()->forceDelete();
        Subfamily::query()->delete();
        Family::query()->delete();

        DB::table('user_locations')->delete();
        User::withTrashed()->forceDelete();
        Location::withTrashed()->forceDelete();

        $mainLocation = Location::create([
            'name' => 'Sucursal Centro',
            'address' => 'Av. Principal 123',
            'latitude' => -0.1807,
            'longitude' => -78.4678,
            'is_active' => true,
            'last_sync_at' => now()->subMinutes(5),
        ]);

        $northLocation = Location::create([
            'name' => 'Sucursal Norte',
            'address' => 'Calle Norte 456',
            'latitude' => -0.1307,
            'longitude' => -78.4803,
            'is_active' => true,
            'last_sync_at' => now()->subMinutes(8),
        ]);

        // NCF SEQUENCE SEEDER
        // Configuración editable en config/pos.php:
        //   ncf.enabled   → toggle global del módulo NCF (true/false)
        //   ncf.country   → EC (01/04/05/07) | DO (E31/E32/E33/E34)
        //   ncf.mode      → global | by_location
        //   ncf.start     → contador inicio rango
        //   ncf.end       → contador fin rango
        $ncfEnabled = config('pos.ncf.enabled', true);
        $ncfCountry = config('pos.ncf.country', 'EC');
        $ncfMode = config('pos.ncf.mode', 'by_location');
        $ncfStart = (int) config('pos.ncf.start', 1);
        $ncfEnd = (int) config('pos.ncf.end', 999999999);

        if ($ncfEnabled) {
            $types = $ncfCountry === 'DO'
                ? ['E31' => 'Bienes', 'E32' => 'Servicios', 'E33' => 'Combustible', 'E34' => 'Importación']
                : ['01' => 'Venta', '04' => 'Nota crédito', '05' => 'Nota débito', '07' => 'Guía remisión'];

            $locationIds = ($ncfMode === 'global')
                ? [null]
                : [$mainLocation->id, $northLocation->id];

            foreach ($types as $tType => $tLabel) {
                foreach ($locationIds as $locId) {
                    NcfSequence::create([
                        'type' => $tType,
                        'location_id' => $locId,
                        'establishment' => '001',
                        'start' => $ncfStart,
                        'end' => $ncfEnd,
                        'current' => $ncfStart,
                    ]);
                }
            }
        }

        $deviceMain01 = Device::create([
            'location_id' => $mainLocation->id,
            'device_fingerprint' => 'POS-CENTRO-01-FP',
            'name' => 'Caja Principal 01',
            'is_enabled' => true,
            'registered_at' => now()->subMonths(2),
            'last_sync_at' => now()->subMinutes(5),
        ]);

        $deviceMain02 = Device::create([
            'location_id' => $mainLocation->id,
            'device_fingerprint' => 'POS-CENTRO-02-FP',
            'name' => 'Caja Auxiliar 02',
            'is_enabled' => true,
            'registered_at' => now()->subMonths(1),
            'last_sync_at' => now()->subMinutes(7),
        ]);

        $deviceNorth01 = Device::create([
            'location_id' => $northLocation->id,
            'device_fingerprint' => 'POS-NORTE-01-FP',
            'name' => 'Caja Norte 01',
            'is_enabled' => true,
            'registered_at' => now()->subWeeks(3),
            'last_sync_at' => now()->subMinutes(8),
        ]);

        License::create([
            'device_id' => $deviceMain01->id,
            'valid_from' => now()->subMonths(1),
            'valid_to' => now()->addMonths(3),
            'status' => 'ACTIVE',
        ]);

        License::create([
            'device_id' => $deviceMain02->id,
            'valid_from' => now()->subWeeks(2),
            'valid_to' => now()->addMonths(2),
            'status' => 'ACTIVE',
        ]);

        License::create([
            'device_id' => $deviceNorth01->id,
            'valid_from' => now()->subMonths(1),
            'valid_to' => now()->addMonths(1),
            'status' => 'ACTIVE',
        ]);

        $admin = User::create([
            'username' => 'admin.pos',
            'full_name' => 'Administrador POS',
            'password' => Hash::make('Admin123!'),
            'pin_sha384' => User::pinSha384FromPlain('Admin123!'),
            'role' => 'ADMIN',
            'is_active' => true,
            'location_id' => $mainLocation->id,
        ]);

        $managerCenter = User::create([
            'username' => 'manager.centro',
            'full_name' => 'Manager Centro',
            'password' => Hash::make('Manager123!'),
            'pin_sha384' => User::pinSha384FromPlain('Manager123!'),
            'role' => 'MANAGER',
            'is_active' => true,
            'location_id' => $mainLocation->id,
        ]);

        $cashierCenter = User::create([
            'username' => 'cashier.centro',
            'full_name' => 'Cajero Centro',
            'password' => Hash::make('Cashier123!'),
            'pin_sha384' => User::pinSha384FromPlain('Cashier123!'),
            'role' => 'CASHIER',
            'is_active' => true,
            'location_id' => $mainLocation->id,
        ]);

        $cashierNorth = User::create([
            'username' => 'cashier.norte',
            'full_name' => 'Cajero Norte',
            'password' => Hash::make('Cashier123!'),
            'pin_sha384' => User::pinSha384FromPlain('Cashier123!'),
            'role' => 'CASHIER',
            'is_active' => true,
            'location_id' => $northLocation->id,
        ]);

        $admin->locations()->attach([$mainLocation->id, $northLocation->id]);
        $managerCenter->locations()->attach([$mainLocation->id]);
        $cashierCenter->locations()->attach([$mainLocation->id]);
        $cashierNorth->locations()->attach([$northLocation->id]);

        $famBebidas = Family::create(['name' => 'Bebidas']);
        $famComida = Family::create(['name' => 'Comida']);

        $subCafe = Subfamily::create(['family_id' => $famBebidas->id, 'name' => 'Café']);
        $subJugos = Subfamily::create(['family_id' => $famBebidas->id, 'name' => 'Jugos y agua']);
        $subSand = Subfamily::create(['family_id' => $famComida->id, 'name' => 'Sandwiches']);
        $subPostres = Subfamily::create(['family_id' => $famComida->id, 'name' => 'Postres']);

        $products = collect([
            ['sku' => 'P-CAFE-001', 'name' => 'Cafe Americano', 'subfamily_id' => $subCafe->id, 'price' => 2.50, 'tax_rate' => 12.00, 'is_active' => true, 'image_url' => 'https://source.unsplash.com/100x100/?coffee'],
            ['sku' => 'P-SAND-001', 'name' => 'Sandwich Mixto', 'subfamily_id' => $subSand->id, 'price' => 4.80, 'tax_rate' => 12.00, 'is_active' => true, 'image_url' => 'https://source.unsplash.com/100x100/?sandwich'],
            ['sku' => 'P-AGUA-001', 'name' => 'Agua 500ml', 'subfamily_id' => $subJugos->id, 'price' => 1.00, 'tax_rate' => 0.00, 'is_active' => true, 'image_url' => 'https://source.unsplash.com/100x100/?water,bottle'],
            ['sku' => 'P-TORTA-001', 'name' => 'Torta Chocolate', 'subfamily_id' => $subPostres->id, 'price' => 3.20, 'tax_rate' => 12.00, 'is_active' => true, 'image_url' => 'https://source.unsplash.com/100x100/?cake,chocolate'],
            ['sku' => 'P-JUGO-001', 'name' => 'Jugo Naranja', 'subfamily_id' => $subJugos->id, 'price' => 2.20, 'tax_rate' => 12.00, 'is_active' => true, 'image_url' => 'https://source.unsplash.com/100x100/?orange,juice'],
        ])->map(fn ($p) => Product::create($p));

        $centerShift = Shift::create([
            'location_id' => $mainLocation->id,
            'device_id' => $deviceMain01->id,
            'user_id' => $cashierCenter->id,
            'shift_number' => 101,
            'start_time' => now()->startOfDay()->addHours(8),
            'end_time' => null,
            'opening_balance' => 120,
            'closing_balance' => null,
        ]);

        $northShift = Shift::create([
            'location_id' => $northLocation->id,
            'device_id' => $deviceNorth01->id,
            'user_id' => $cashierNorth->id,
            'shift_number' => 45,
            'start_time' => now()->startOfDay()->addHours(9),
            'end_time' => null,
            'opening_balance' => 90,
            'closing_balance' => null,
        ]);

        $tx1 = Transaction::create([
            'external_id' => 'CENTRO-01-TX-0001',
            'location_id' => $mainLocation->id,
            'device_id' => $deviceMain01->id,
            'shift_id' => $centerShift->id,
            'user_id' => $cashierCenter->id,
            'turn_number' => 101,
            'status' => 'PAID',
            'total' => 7.30,
            'occurred_at' => now()->subHours(2),
            'is_synced' => true,
            'synced_at' => now()->subHours(2)->addMinutes(1),
        ]);

        TransactionItem::create([
            'transaction_id' => $tx1->id,
            'product_id' => $products[0]->id,
            'product_name' => $products[0]->name,
            'product_sku' => $products[0]->sku,
            'qty' => 1,
            'unit_price' => 2.50,
            'discount' => 0,
            'tax' => 0.30,
            'line_total' => 2.80,
        ]);

        TransactionItem::create([
            'transaction_id' => $tx1->id,
            'product_id' => $products[1]->id,
            'product_name' => $products[1]->name,
            'product_sku' => $products[1]->sku,
            'qty' => 1,
            'unit_price' => 4.80,
            'discount' => 0.30,
            'tax' => 0,
            'line_total' => 4.50,
        ]);

        TransactionPayment::create([
            'transaction_id' => $tx1->id,
            'payment_method' => 'CARD',
            'amount' => 7.30,
            'reference' => 'CARD-9981',
        ]);

        $tx2 = Transaction::create([
            'external_id' => 'NORTE-01-TX-0001',
            'location_id' => $northLocation->id,
            'device_id' => $deviceNorth01->id,
            'shift_id' => $northShift->id,
            'user_id' => $cashierNorth->id,
            'turn_number' => 45,
            'status' => 'PAID',
            'total' => 6.40,
            'occurred_at' => now()->subHour(),
            'is_synced' => true,
            'synced_at' => now()->subHour()->addSeconds(40),
        ]);

        TransactionItem::create([
            'transaction_id' => $tx2->id,
            'product_id' => $products[3]->id,
            'product_name' => $products[3]->name,
            'product_sku' => $products[3]->sku,
            'qty' => 2,
            'unit_price' => 3.20,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 6.40,
        ]);

        TransactionPayment::create([
            'transaction_id' => $tx2->id,
            'payment_method' => 'CASH',
            'amount' => 6.40,
            'reference' => null,
        ]);

        $tx3 = Transaction::create([
            'external_id' => 'CENTRO-02-TX-0002',
            'location_id' => $mainLocation->id,
            'device_id' => $deviceMain02->id,
            'shift_id' => $centerShift->id,
            'user_id' => $cashierCenter->id,
            'turn_number' => 101,
            'status' => 'PAID',
            'total' => 3.20,
            'occurred_at' => now()->subMinutes(25),
            'is_synced' => false,
            'synced_at' => null,
        ]);

        TransactionItem::create([
            'transaction_id' => $tx3->id,
            'product_id' => $products[4]->id,
            'product_name' => $products[4]->name,
            'product_sku' => $products[4]->sku,
            'qty' => 1,
            'unit_price' => 2.20,
            'discount' => 0,
            'tax' => 0.26,
            'line_total' => 2.46,
        ]);

        TransactionItem::create([
            'transaction_id' => $tx3->id,
            'product_id' => $products[2]->id,
            'product_name' => $products[2]->name,
            'product_sku' => $products[2]->sku,
            'qty' => 1,
            'unit_price' => 1.00,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 1.00,
        ]);

        TransactionPayment::create([
            'transaction_id' => $tx3->id,
            'payment_method' => 'TRANSFER',
            'amount' => 3.20,
            'reference' => 'TRX-8001',
        ]);

        SyncState::create([
            'location_id' => $mainLocation->id,
            'device_id' => $deviceMain01->id,
            'last_pull_at' => now()->subMinutes(6),
            'last_push_at' => now()->subMinutes(5),
            'last_success_at' => now()->subMinutes(5),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        SyncState::create([
            'location_id' => $mainLocation->id,
            'device_id' => $deviceMain02->id,
            'last_pull_at' => now()->subMinutes(9),
            'last_push_at' => now()->subMinutes(7),
            'last_success_at' => now()->subMinutes(7),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        SyncState::create([
            'location_id' => $northLocation->id,
            'device_id' => $deviceNorth01->id,
            'last_pull_at' => now()->subMinutes(10),
            'last_push_at' => now()->subMinutes(8),
            'last_success_at' => now()->subMinutes(8),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        SyncLog::create([
            'location_id' => $mainLocation->id,
            'device_id' => $deviceMain01->id,
            'operation' => 'PUSH',
            'entity' => 'transactions',
            'records_count' => 2,
            'status' => 'SUCCESS',
            'started_at' => now()->subMinutes(5)->subSeconds(15),
            'finished_at' => now()->subMinutes(5),
            'error_message' => null,
        ]);

        SyncLog::create([
            'location_id' => $northLocation->id,
            'device_id' => $deviceNorth01->id,
            'operation' => 'PULL',
            'entity' => 'users',
            'records_count' => 2,
            'status' => 'SUCCESS',
            'started_at' => now()->subMinutes(8)->subSeconds(12),
            'finished_at' => now()->subMinutes(8),
            'error_message' => null,
        ]);

        $allPermissionNames = AdminRbac::allCrudPermissionNames();
        foreach ($allPermissionNames as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'admin']);
        }

        $superAdminRole = Role::create(['name' => 'super-admin', 'guard_name' => 'admin']);
        $superAdminRole->syncPermissions($allPermissionNames);

        $opsKeys = config('admin_rbac.ops_screen_keys', []);
        $opsManagerPerms = [];
        $opsViewerPerms = [];
        foreach ($opsKeys as $screenKey) {
            $p = AdminRbac::permissionsForScreen($screenKey);
            $opsManagerPerms[] = $p['view'];
            $opsManagerPerms[] = $p['edit'];
            $opsManagerPerms[] = $p['delete'];
            $opsViewerPerms[] = $p['view'];
        }

        // Reportes dedicados fuera del CRUD genérico
        foreach (['dashboard.view', 'dashboard_technical.view', 'cierre_caja.view', 'audit_log.view'] as $extra) {
            Permission::firstOrCreate(['name' => $extra, 'guard_name' => 'admin']);
            $opsManagerPerms[] = $extra;
            $opsViewerPerms[] = $extra;
        }

        $opsManagerRole = Role::create(['name' => 'ops-manager', 'guard_name' => 'admin']);
        $opsManagerRole->syncPermissions($opsManagerPerms);

        $opsViewerRole = Role::create(['name' => 'ops-viewer', 'guard_name' => 'admin']);
        $opsViewerRole->syncPermissions($opsViewerPerms);

        $backendAdmin = AdminUser::create([
            'name' => 'Backend Admin',
            'email' => 'backend.admin@demo.local',
            'password' => Hash::make('Backend123!'),
            'is_active' => true,
        ]);
        $backendAdmin->assignRole($superAdminRole);
    }
}
