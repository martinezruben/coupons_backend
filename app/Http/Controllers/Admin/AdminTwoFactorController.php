<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminActive;
use App\Models\AdminUser;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2FA TOTP opt-in para admins: setup con QR, confirmación con código,
 * códigos de recuperación de un solo uso y desafío en el login.
 */
class AdminTwoFactorController extends Controller
{
    private const RECOVERY_COUNT = 8;

    private function google2fa(): Google2FA
    {
        return new Google2FA;
    }

    /* ------------------------------------------------------------------
     |  Gestión (usuario autenticado)
     * ----------------------------------------------------------------- */

    public function show(Request $request)
    {
        $admin = auth('admin')->user();
        $pendingSecret = null;

        if (! $admin->totp_enabled && ! $request->session()->has('admin_2fa_pending')) {
            $pendingSecret = $this->google2fa()->generateSecretKey(32);
            $request->session()->put('admin_2fa_pending', $pendingSecret);
        } elseif (! $admin->totp_enabled) {
            $pendingSecret = $request->session()->get('admin_2fa_pending');
        }

        $qrSvg = null;
        $secretText = null;
        if ($pendingSecret) {
            $secretText = $pendingSecret;
            $qrSvg = $this->qrSvg(
                $this->google2fa()->getQRCodeUrl(
                    config('app.name', 'Coupify'),
                    $admin->email,
                    $pendingSecret,
                ),
            );
        }

        return view('admin.two-factor', [
            'admin' => $admin,
            'pendingSecret' => $pendingSecret,
            'secretText' => $secretText,
            'qrSvg' => $qrSvg,
            'recoveryCodes' => $request->session()->get('admin_2fa_recovery'),
        ]);
    }

    public function enable(Request $request)
    {
        $admin = auth('admin')->user();
        $secret = $request->session()->get('admin_2fa_pending');
        abort_if(! $secret, 400, 'No hay un setup de 2FA pendiente.');

        $request->validate(['code' => ['required', 'digits:6']]);

        $valid = $this->google2fa()->verifyKey($secret, $request->input('code'), 1);
        if (! $valid) {
            return back()->withErrors(['code' => 'Código inválido. Vuelve a intentarlo.']);
        }

        $recovery = $this->generateRecoveryCodes();

        $admin->forceFill([
            'totp_secret' => Crypt::encryptString($secret),
            'totp_enabled' => true,
            'totp_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $recovery)),
        ])->save();

        $request->session()->forget('admin_2fa_pending');
        $request->session()->put('admin_2fa_recovery', $recovery);

        return redirect()->route('admin.2fa.show')->with('status', '2FA activado. Guarda tus códigos de recuperación: se muestran una sola vez.');
    }

    public function disable(Request $request)
    {
        $admin = auth('admin')->user();
        $request->validate(['code' => ['required', 'string']]);

        if (! $this->verifyCodeOrRecovery($admin, $request->input('code'))) {
            return back()->withErrors(['code' => 'Código inválido.']);
        }

        $admin->forceFill(['totp_secret' => null, 'totp_enabled' => false, 'totp_recovery_codes' => null])->save();

        return redirect()->route('admin.2fa.show')->with('status', '2FA desactivado.');
    }

    /* ------------------------------------------------------------------
     |  Desafío en el login (sin sesión)
     * ----------------------------------------------------------------- */

    public function challenge(Request $request)
    {
        abort_if(! $this->pendingUser($request), 404);

        return view('admin.two-factor-challenge');
    }

    public function verify(Request $request)
    {
        $pending = $this->pendingUser($request);
        abort_if(! $pending, 404);

        $throttleKey = 'admin-2fa:'.$pending->getKey().'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['code' => "Demasiados intentos. Espera {$seconds} segundos."]);
        }

        $request->validate(['code' => ['required', 'string']]);

        if ($this->verifyCodeOrRecovery($pending, $request->input('code'))) {
            RateLimiter::clear($throttleKey);
            $remember = $request->session()->pull('admin_2fa_remember', false);
            $request->session()->forget('admin_2fa_id');
            auth('admin')->login($pending, $remember);
            $request->session()->regenerate();
            EnsureAdminActive::markSessionStart($request);

            return redirect()->intended(route('admin.dashboard'));
        }

        RateLimiter::hit($throttleKey, 300);

        return back()->withErrors(['code' => 'Código inválido.']);
    }

    /* ------------------------------------------------------------------
     |  Helpers
     * ----------------------------------------------------------------- */

    private function pendingUser(Request $request): ?AdminUser
    {
        $id = $request->session()->get('admin_2fa_id');
        if (! $id) {
            return null;
        }

        $user = AdminUser::find($id);
        if (! $user || ! $user->is_active || ! $user->totp_enabled) {
            $request->session()->forget(['admin_2fa_id', 'admin_2fa_remember']);

            return null;
        }

        return $user;
    }

    private function verifyCodeOrRecovery(AdminUser $admin, string $code): bool
    {
        $code = trim($code);

        // ¿TOTP? (ventana de ±1 periodo por drift de reloj)
        if (preg_match('/^\d{6}$/', $code) && $admin->totp_secret) {
            try {
                if ($this->google2fa()->verifyKey(Crypt::decryptString($admin->totp_secret), $code, 1)) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        // ¿Código de recuperación de un solo uso?
        if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code) && $admin->totp_recovery_codes) {
            $hashed = json_decode((string) $admin->totp_recovery_codes, true) ?: [];
            foreach ($hashed as $i => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashed[$i]);
                    $admin->forceFill(['totp_recovery_codes' => json_encode(array_values($hashed))])->save();

                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
        }

        return $codes;
    }

    private function qrSvg(string $content): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($content);
    }
}
