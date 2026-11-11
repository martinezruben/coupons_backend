<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith2fa(array &$recoveryPlain = []): AdminUser
    {
        $secret = (new Google2FA)->generateSecretKey(32);
        $recoveryPlain = ['ABCD-1234', 'WXYZ-5678'];

        return AdminUser::factory()->create([
            'totp_secret' => Crypt::encryptString($secret),
            'totp_enabled' => true,
            'totp_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $recoveryPlain)),
        ]);
    }

    private function currentCode(AdminUser $admin): string
    {
        return (new Google2FA)->getCurrentOtp(Crypt::decryptString($admin->totp_secret));
    }

    public function test_login_with_2fa_shows_challenge_and_blocks_dashboard(): void
    {
        $admin = $this->adminWith2fa();

        $res = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $res->assertRedirect(route('admin.2fa.challenge'));
        // no debe quedar logueado hasta verificar el código
        $this->assertGuest('admin');
    }

    public function test_challenge_accepts_valid_totp_and_logs_in(): void
    {
        $admin = $this->adminWith2fa();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);
        $session = $this->app['session'];

        $code = (new Google2FA)->getCurrentOtp(Crypt::decryptString($admin->totp_secret));
        $this->post(route('admin.2fa.verify'), ['code' => $code]);

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNull($session->get('admin_2fa_id'));
    }

    public function test_challenge_accepts_recovery_code_once(): void
    {
        $admin = $this->adminWith2fa();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);

        $this->post(route('admin.2fa.verify'), ['code' => 'ABCD-1234']);
        $this->assertAuthenticatedAs($admin, 'admin');

        // el código de recuperación quedó consumido
        $this->assertCount(1, json_decode($admin->fresh()->totp_recovery_codes, true));
    }

    public function test_challenge_rejects_invalid_code(): void
    {
        $admin = $this->adminWith2fa();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);
        $this->post(route('admin.2fa.verify'), ['code' => '000000']);

        $this->assertGuest('admin');
        $this->assertNotNull(session('admin_2fa_id'), 'El desafío sigue pendiente');
    }

    public function test_totp_without_2fa_logs_in_directly(): void
    {
        $admin = AdminUser::factory()->create(['totp_enabled' => false]);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_setup_flow_enables_2fa(): void
    {
        $admin = AdminUser::factory()->create();
        $this->actingAs($admin, 'admin');

        // iniciar setup: genera secreto pendiente + QR
        $res = $this->get(route('admin.2fa.show'));
        $res->assertOk()->assertSee('Escanea el código');
        $pending = session('admin_2fa_pending');
        $this->assertNotEmpty($pending);

        // confirmar con código válido del secreto pendiente
        $code = (new Google2FA)->getCurrentOtp($pending);
        $this->followingRedirects()->post(route('admin.2fa.enable'), ['code' => $code])
            ->assertOk()
            ->assertSee('códigos de recuperación');

        $admin->refresh();
        $this->assertTrue((bool) $admin->totp_enabled);
        $this->assertCount(8, json_decode($admin->totp_recovery_codes, true));
    }

    public function test_disable_requires_valid_code(): void
    {
        $admin = $this->adminWith2fa();
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.2fa.disable'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertTrue((bool) $admin->fresh()->totp_enabled);

        $this->post(route('admin.2fa.disable'), ['code' => 'ABCD-1234']);
        $this->assertFalse((bool) $admin->fresh()->totp_enabled);
    }
}
