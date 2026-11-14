<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Models\User;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Support\CouponToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CouponBatchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    private function payload(array $over = []): array
    {
        return array_merge(['name' => 'Navidad', 'coupon_type' => 'QRCODE', 'quantity' => 5, 'amount' => '25.50', 'prefix' => 'nav', 'expire_date' => now()->addMonth()->toDateString()], $over);
    }

    private function generate(AdminUser $admin, array $over = []): CouponBatch
    {
        $batch = CouponBatchGenerator::create($this->payload($over), $admin, null);
        (new CouponBatchGenerator)->generate($batch);

        return $batch->fresh();
    }

    /** @return list<array<string, string>> filas del CSV indexadas por encabezado */
    private function csvRows(CouponBatch $batch): array
    {
        $lines = array_map('str_getcsv', file(Storage::disk('local')->path($batch->export_path), FILE_IGNORE_NEW_LINES));
        $lines[0][0] = ltrim($lines[0][0], "\xEF\xBB\xBF");
        $header = array_shift($lines);

        return array_map(fn ($l) => array_combine($header, $l), $lines);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    // ---- Generación

    public function test_generates_inactive_coupons_with_random_numbers_hashed_tokens_and_a_csv(): void
    {
        $admin = $this->admin();
        $batch = $this->generate($admin, ['quantity' => 50]);

        $this->assertSame('ready', $batch->status);
        $this->assertSame(50, $batch->generated_count);
        $this->assertSame(50, DB::table('coupons')->where('batch_id', $batch->id)->count());

        $rows = DB::table('coupons')->where('batch_id', $batch->id)->orderBy('seq')->get();
        $this->assertSame(range(1, 50), $rows->pluck('seq')->all());
        foreach ($rows as $row) {
            $this->assertSame('GENERATED', $row->status);
            $this->assertSame(0, (int) $row->coupon_active);
            $this->assertNull($row->customer_id);
            $this->assertMatchesRegularExpression('/^NAV\d{12}$/', $row->coupon_number);
            $this->assertSame(71 - 4, strlen($row->coupon_token)); // h1: + sha256
            $this->assertStringStartsWith('h1:', $row->coupon_token);
            $this->assertSame('25.50', number_format((float) $row->coupon_balance, 2, '.', ''));
        }
        $this->assertCount(50, $rows->pluck('coupon_number')->unique());

        // El CSV es el único lugar con el token en claro, y coincide con el hash guardado
        $csv = $this->csvRows($batch);
        $this->assertCount(50, $csv);
        $first = $csv[0];
        $this->assertSame($batch->code, $first['lote']);
        $this->assertSame("@coupon={$first['numero']}@token={$first['token']}", $first['contenido_qr']);
        $this->assertSame(32, strlen($first['token']));
        $stored = DB::table('coupons')->where('coupon_number', $first['numero'])->value('coupon_token');
        $this->assertTrue(CouponToken::matches($stored, $first['token']));
        $this->assertFalse(CouponToken::matches($stored, 'otro'));
        $this->assertNotNull($batch->export_expires_at);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'batch_generate', 'batch_id' => $batch->id]);
    }

    public function test_barcode_batches_have_no_token_column(): void
    {
        $batch = $this->generate($this->admin(), ['coupon_type' => 'CODBAR', 'prefix' => null, 'quantity' => 3]);

        $this->assertSame(3, DB::table('coupons_codbar')->where('batch_id', $batch->id)->count());
        $row = $this->csvRows($batch)[0];
        $this->assertArrayNotHasKey('token', $row);
        $this->assertMatchesRegularExpression('/^\d{12}$/', $row['numero']);
    }

    public function test_numbers_never_collide_with_existing_coupons(): void
    {
        $this->generate($this->admin(), ['quantity' => 200, 'prefix' => null]);
        $this->generate($this->admin(), ['quantity' => 200, 'prefix' => null, 'coupon_type' => 'CODBAR']);

        $all = array_merge(DB::table('coupons')->pluck('coupon_number')->all(), DB::table('coupons_codbar')->pluck('coupon_number')->all());
        $this->assertCount(400, array_unique($all));
    }

    public function test_a_failed_generation_leaves_nothing_behind(): void
    {
        $batch = CouponBatchGenerator::create($this->payload(['quantity' => 5]), $this->admin(), null);
        DB::statement('DROP TABLE coupons'); // fuerza el fallo a mitad de camino

        (new CouponBatchGenerator)->generate($batch);

        $batch->refresh();
        $this->assertSame('failed', $batch->status);
        $this->assertNull($batch->export_path);
        $this->assertFalse(Storage::disk('local')->exists('exports/coupons/'.$batch->id.'.csv'));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'batch_failed', 'batch_id' => $batch->id]);
    }

    public function test_generates_the_maximum_size_of_20000(): void
    {
        $batch = $this->generate($this->admin(), ['quantity' => 20000, 'coupon_type' => 'CODBAR', 'prefix' => null]);

        $this->assertSame(20000, DB::table('coupons_codbar')->where('batch_id', $batch->id)->count());
        $this->assertCount(20000, $this->csvRows($batch));
    }

    // ---- Panel: creación, validación y permisos

    public function test_creating_a_batch_from_the_panel_runs_the_job_and_audits(): void
    {
        $admin = $this->admin('coupon_batches.view', 'coupon_batches.generate');

        $this->actingAs($admin, 'admin')->get(route('admin.coupon-batches.create'))->assertOk();
        $res = $this->post(route('admin.coupon-batches.store'), $this->payload(['quantity' => 7]));

        $batch = CouponBatch::first();
        $res->assertRedirect(route('admin.coupon-batches.show', $batch));
        $this->assertSame('ready', $batch->status);
        $this->assertSame(7, $batch->generated_count);
        $this->assertSame($admin->id, (int) $batch->created_by_admin_id);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'batch_create', 'batch_id' => $batch->id, 'admin_user_id' => $admin->id]);
    }

    public function test_batch_creation_validates_input(): void
    {
        $this->actingAs($this->admin('coupon_batches.generate'), 'admin');

        foreach ([
            ['quantity' => 0], ['quantity' => 20001], ['quantity' => 'x'], ['amount' => -1], ['amount' => 0], ['amount' => 1.005],
            ['expire_date' => now()->subDay()->toDateString()], ['prefix' => 'bad prefix!'], ['prefix' => 'TOOLONG1'],
            ['coupon_type' => 'OTRO'], ['name' => ''],
        ] as $bad) {
            $this->post(route('admin.coupon-batches.store'), $this->payload($bad))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('coupon_batches', 0);
    }

    public function test_every_batch_action_needs_its_own_permission(): void
    {
        $batch = $this->generate($this->admin());
        $customer = Customer::create(['name' => 'Cliente X']);
        $viewer = $this->admin('coupon_batches.view');
        $this->actingAs($viewer, 'admin');

        $this->get(route('admin.coupon-batches.create'))->assertForbidden();
        $this->post(route('admin.coupon-batches.store'), $this->payload())->assertForbidden();
        $this->get(route('admin.coupon-batches.download', $batch))->assertForbidden();
        $this->get(route('admin.coupon-batches.activate.form', $batch))->assertForbidden();
        $this->post(route('admin.coupon-batches.activate', $batch), ['selection_type' => 'all', 'customer_id' => $customer->id, 'mode' => 'now'])->assertForbidden();
        $this->post(route('admin.coupon-batches.unassign', $batch), ['selection_type' => 'all'])->assertForbidden();
        $this->post(route('admin.coupon-batches.void', $batch), ['reason' => 'Motivo largo'])->assertForbidden();
        $this->get(route('admin.coupon-batches.show', $batch))->assertOk(); // ver sí

        $this->actingAs($this->admin(), 'admin')->get(route('admin.coupon-batches.show', $batch))->assertForbidden();
    }

    // ---- Los tokens solo existen en el archivo

    public function test_tokens_are_never_shown_on_any_screen(): void
    {
        $batch = $this->generate($this->admin(), ['quantity' => 3]);
        $tokens = array_column($this->csvRows($batch), 'token');
        $viewer = $this->admin('coupon_batches.view', 'coupons.view', 'coupon_batches.download', 'coupon_batches.activate');
        $this->actingAs($viewer, 'admin');

        foreach ([
            route('admin.coupon-batches.show', $batch),
            route('admin.screens.index', 'coupons'),
            route('admin.screens.index', 'coupon-batches'),
            route('admin.screens.index', 'coupon-audit-logs'),
            route('admin.coupon-batches.activate.form', $batch),
        ] as $url) {
            $html = $this->get($url)->getContent();
            foreach ($tokens as $token) {
                $this->assertStringNotContainsString($token, $html, "token visible en $url");
            }
            $this->assertStringNotContainsString('@token=', $html);
        }
    }

    public function test_the_file_downloads_once_then_is_gone_and_is_audited(): void
    {
        $generator = $this->admin('coupon_batches.view');
        $batch = $this->generate($generator, ['quantity' => 4]);
        $expected = $this->csvRows($batch);
        $downloader = $this->admin('coupon_batches.view', 'coupon_batches.download');

        $res = $this->actingAs($downloader, 'admin')->get(route('admin.coupon-batches.download', $batch))->assertOk();
        $content = $res->streamedContent();
        $this->assertStringContainsString($expected[0]['token'], $content);
        $this->assertStringContainsString("{$batch->code}.csv", $res->headers->get('content-disposition'));

        $batch->refresh();
        $this->assertNull($batch->export_path);
        $this->assertSame(1, (int) $batch->export_downloads);
        $this->assertFalse(Storage::disk('local')->exists('exports/coupons/'.$batch->id.'.csv'));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'batch_download', 'batch_id' => $batch->id, 'admin_user_id' => $downloader->id]);

        $this->get(route('admin.coupon-batches.download', $batch))->assertRedirect()->assertSessionHasErrors('coupon');
    }

    public function test_expired_files_are_purged_without_a_download(): void
    {
        $batch = $this->generate($this->admin());
        $this->assertTrue(Storage::disk('local')->exists($batch->export_path));

        $this->travel(config('coupons.export_ttl_hours') + 1)->hours();
        $this->artisan('coupons:purge-exports')->assertSuccessful();

        $batch->refresh();
        $this->assertNull($batch->export_path);
        $this->assertFalse(Storage::disk('local')->exists('exports/coupons/'.$batch->id.'.csv'));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'export_expired', 'batch_id' => $batch->id]);

        $this->actingAs($this->admin('coupon_batches.download'), 'admin')
            ->get(route('admin.coupon-batches.download', $batch))->assertSessionHasErrors('coupon');
    }

    public function test_legacy_plaintext_tokens_are_hashed_by_the_command_and_keep_working(): void
    {
        DB::table('coupons')->insert(['coupon_number' => 'L1', 'coupon_token' => 'plain-token', 'coupon_active' => 1, 'coupon_amount' => '10', 'coupon_balance' => '10']);
        $this->assertTrue(CouponToken::matches('plain-token', 'plain-token')); // heredado en claro sigue validando

        $this->artisan('coupons:hash-tokens', ['--force' => true])->assertSuccessful();
        $stored = DB::table('coupons')->where('coupon_number', 'L1')->value('coupon_token');
        $this->assertStringStartsWith('h1:', $stored);
        $this->assertTrue(CouponToken::matches($stored, 'plain-token'));
        $this->artisan('coupons:hash-tokens', ['--force' => true])->expectsOutput('No hay tokens en claro.')->assertSuccessful();
    }

    // ---- Ciclo de vida de punta a punta con la API de la app

    public function test_generated_coupons_cannot_be_used_until_activated_then_work_with_the_csv_token(): void
    {
        $generator = $this->admin();
        $batch = $this->generate($generator, ['quantity' => 3]);
        $row = $this->csvRows($batch)[0];
        $user = User::factory()->create(['role' => 'CASHIER']);
        Sanctum::actingAs($user, $user->couponAbilities());
        $query = "coupon_number={$row['numero']}&coupon_token={$row['token']}";
        $redeem = ['amount' => 5, 'coupons' => [$row['numero']], 'tokens' => [$row['token']]];

        // Generado: existe pero no está activo ni se puede cobrar
        $this->getJson("/api/balance?$query")->assertOk()->assertJsonPath('active', false);
        $this->postJson('/api/transaction', $redeem)->assertStatus(422);

        // Activado por otra persona para un cliente
        $operator = $this->admin();
        $customer = Customer::create(['name' => 'Cliente X']);
        app(CouponActivationService::class)->submit($batch, $customer, 'now', null, 'all', [], null, $operator, null);

        $this->getJson("/api/balance?$query")->assertOk()->assertJsonPath('active', true)->assertJsonPath('balance', '25.50');
        $this->postJson('/api/transaction', $redeem)->assertOk();
        $this->postJson('/api/transaction', ['tokens' => ['mal']] + $redeem)->assertStatus(404);
    }
}
