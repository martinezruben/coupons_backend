<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminActive;
use App\Models\AdminUser;
use App\Models\SystemParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function create()
    {
        // Diagnóstico solo fuera de producción: la página de login es pública y
        // no debe revelar si la instalación tiene administradores
        $dbStatus = app()->isProduction()
            ? null
            : (AdminUser::query()->where('is_active', true)->exists() ? 'OK' : 'EMPTY');

        return view('admin.auth.login', compact('dbStatus'));
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $params = SystemParameter::query()->first();
        $maxAttempts = $params !== null ? max(1, (int) $params->admin_max_failed_login_attempts) : 5;
        $lockoutMinutes = $params !== null ? max(1, (int) $params->admin_lockout_minutes) : 15;

        $throttleKey = 'admin-login:'.strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => 'Demasiados intentos fallidos. Podrá volver a intentarlo en '.$seconds.' segundos.',
            ])->onlyInput('email');
        }

        // Solo cuentas activas: una cuenta desactivada responde igual que unas credenciales inválidas
        if (Auth::guard('admin')->attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);

            // 2FA: posponer el login hasta verificar el código TOTP
            /** @var AdminUser $admin */
            $admin = Auth::guard('admin')->user();
            if ($admin->totp_enabled) {
                $remember = $request->boolean('remember');
                Auth::guard('admin')->logout();
                $request->session()->put('admin_2fa_id', $admin->getKey());
                $request->session()->put('admin_2fa_remember', $remember);
                $request->session()->migrate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.2fa.challenge');
            }

            $request->session()->regenerate();
            EnsureAdminActive::markSessionStart($request);

            return redirect()->intended(route('admin.dashboard'));
        }

        RateLimiter::hit($throttleKey, $lockoutMinutes * 60);

        return back()->withErrors(['email' => 'Credenciales inválidas.'])->onlyInput('email');
    }

    public function destroy(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
