<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CouponLoginRequest;
use App\Models\User;
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

        if (! $user || ! $valid || ! $user->is_active) {
            return response()->json(['error' => 'Unauthorized'], 401);
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
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'user' => $request->user()->toApi()]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Solo el token actual: no cierra la sesión de los otros dispositivos.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logout successful.']);
    }
}
