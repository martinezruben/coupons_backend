<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\SystemParameter;
use App\Support\AdminRbac;
use App\Support\CouponSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class SystemSettingsController extends Controller
{
    public function edit(): Response
    {
        $this->authorizeSettings('view');

        $params = SystemParameter::query()->firstOrFail();

        return response()->view('admin.system-settings.edit', [
            'params' => $params,
            'couponDefs' => collect(CouponSettings::definitions())->groupBy(fn ($d) => $d[0], true),
            'couponValues' => CouponSettings::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeSettings('edit');

        $validated = $request->validate(CouponSettings::rules() + [
            'admin_password_min_length' => ['required', 'integer', 'min:6', 'max:128'],
            'pos_password_min_length' => ['required', 'integer', 'min:3', 'max:32'],
            'admin_max_failed_login_attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'admin_lockout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'mail_driver' => ['sometimes', 'required', 'in:smtp,365'],
            'mail_from_address' => ['nullable', 'email'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl'],
            'office365_tenant_id' => ['nullable', 'string', 'max:255'],
            'office365_client_id' => ['nullable', 'string', 'max:255'],
            'office365_client_secret' => ['nullable', 'string'],
            'office365_scopes' => ['nullable', 'string'],
            'min_app_version' => ['nullable', 'string', 'max:30', 'regex:/^\d+(\.\d+){0,3}$/'],
            'contingency_enabled' => ['boolean'],
            'contingency_email_list' => ['nullable', 'string'],
            'contingency_resend_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:168'],
        ]);

        foreach ([
            'admin_password_require_uppercase',
            'admin_password_require_lowercase',
            'admin_password_require_digit',
            'admin_password_require_symbol',
            'pos_password_require_uppercase',
            'pos_password_require_lowercase',
            'pos_password_require_digit',
            'pos_password_require_symbol',
            'contingency_enabled',
        ] as $boolField) {
            $validated[$boolField] = $request->boolean($boolField);
        }

        // Vacío = sin versión mínima
        if (array_key_exists('min_app_version', $validated)) {
            $validated['min_app_version'] = trim((string) $validated['min_app_version']) ?: null;
        }

        if (! empty($validated['office365_scopes'])) {
            $validated['office365_scopes'] = json_decode($validated['office365_scopes'], true);
        }

        foreach (['smtp_password', 'office365_client_secret'] as $secret) {
            if (($validated[$secret] ?? '') === '') {
                unset($validated[$secret]);
            }
        }

        if (array_key_exists('contingency_email_list', $validated)) {
            $emails = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) $validated['contingency_email_list']))));
            $invalid = array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false);
            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    'contingency_email_list' => 'Correos no válidos: '.implode(', ', $invalid),
                ]);
            }
            $validated['contingency_email_list'] = $emails;
        }

        $this->saveCouponSettings($request, $validated);
        unset($validated['coupon']);

        $params = SystemParameter::query()->firstOrFail();
        $params->update($validated);

        if ($request->wantsJson()) {
            return response()->json(['status' => 'Parámetros guardados correctamente.']);
        }

        return redirect()
            ->route('admin.system-settings.edit')
            ->with('status', 'Parámetros guardados correctamente.');
    }

    /** Guarda los parámetros de cupones que cambiaron y deja en la auditoría el antes y el después. */
    private function saveCouponSettings(Request $request, array $validated): void
    {
        $changes = [];
        $new = [];
        foreach (CouponSettings::definitions() as $key => [, $type]) {
            if (! array_key_exists(CouponSettings::field($key), $validated['coupon'] ?? [])) {
                continue;
            }
            $value = CouponSettings::cast($validated['coupon'][CouponSettings::field($key)], $type);
            $old = config("coupons.$key");
            if ($value !== $old && ! ($type === 'decimal' && (float) $value === (float) $old)) {
                $changes[$key] = ['antes' => $old, 'después' => $value];
                $new[$key] = $value;
            }
        }
        if ($new !== []) {
            CouponSettings::save($new);
            AdminAuditLog::record('update', 'coupon_settings', null, $changes);
        }
    }

    private function authorizeSettings(string $action): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        $p = AdminRbac::permissionsForScreen('system-settings');
        $perm = $action === 'edit' ? $p['edit'] : $p['view'];
        abort_unless($user->can($perm), 403);
    }
}
