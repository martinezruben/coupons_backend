<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponLoginRequest;
use App\Models\Device;
use App\Models\User;
use App\Services\DeviceBinding;
use App\Services\SecurityAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Inicio de sesión de la app de cupones (usuarios de la app, no del panel). */
class CouponAuthController extends Controller
{
    public function login(CouponLoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($request->input('username'))])
            ->first();

        // Siempre se hace un Hash::check (aunque el usuario no exista) para que el tiempo de
        // respuesta no revele si el usuario está registrado.
        $valid = Hash::check(
            $request->input('password'),
            $user?->password ?? Hash::make(Str::random(32))
        );

        // Cuenta bloqueada por intentos fallidos: misma respuesta que una clave incorrecta (no se revela el bloqueo)
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            return response()->json(['error' => 'Unauthorized', 'code' => 'invalid_credentials'], 401);
        }

        if (! $user || ! $valid || ! $user->is_active) {
            if ($user) {
                $this->registerFailure($user);
            }

            return response()->json(['error' => 'Unauthorized', 'code' => 'invalid_credentials'], 401);
        }

        // La terminal debe estar registrada y el usuario pertenecer a su localidad (solo se evalúa con la clave ya verificada)
        DeviceBinding::resolve($request, $user);

        if ($user->failed_login_attempts > 0 || $user->locked_until) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
        }

        // Un token por dispositivo: volver a iniciar sesión con el mismo nombre reemplaza el anterior.
        $device = $request->input('device_name') ?: 'app-'.Str::random(8);
        $user->tokens()->where('name', $device)->delete();

        $token = $user->createToken(
            $device,
            $user->couponAbilities(),
            now()->addMinutes((int) config('coupons.token_ttl_minutes'))
        )->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user->toApi(),
            ...$this->locationPayload($request, $user),
        ]);
    }

    /** Cuenta los intentos fallidos y bloquea la cuenta al llegar al máximo (parámetros de cupones). */
    private function registerFailure(User $user): void
    {
        $max = (int) config('coupons.login_max_attempts');
        if ($max <= 0) {
            return;
        }
        $attempts = $user->failed_login_attempts + 1;
        if ($attempts < $max) {
            $user->forceFill(['failed_login_attempts' => $attempts])->save();

            return;
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => now()->addMinutes((int) config('coupons.login_lockout_minutes'))])->save();
        $user->tokens()->delete();
        SecurityAlerts::raise('user_locked', 'high', "La cuenta {$user->username} se bloqueó por {$max} intentos de inicio de sesión fallidos.", null, (string) $user->getKey(), $user->location_id ? (string) $user->location_id : null);
    }

    /**
     * Localidad con la que opera la app: la del dispositivo (Device-Fingerprint) y, si no hay, la del usuario.
     * Incluye el número de dispensadores (1 a 20).
     *
     * @return array<string, mixed>
     */
    private function locationPayload(Request $request, User $user): array
    {
        $fingerprint = $request->header('Device-Fingerprint');
        $location = is_string($fingerprint) && $fingerprint !== ''
            ? Device::query()->where('device_fingerprint', $fingerprint)->first()?->location
            : null;
        $location ??= $user->location ?? $user->locations()->first();

        return $location ? ['location' => $location->toApi(), 'dispensers' => (int) $location->dispensers] : ['location' => null, 'dispensers' => null];
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'user' => $request->user()->toApi()] + $this->locationPayload($request, $request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        // Solo el token actual: no cierra la sesión de los otros dispositivos.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logout successful.']);
    }
}
