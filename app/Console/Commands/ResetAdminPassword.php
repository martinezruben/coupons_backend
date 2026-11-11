<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Restablece la contraseña de un administrador desde la consola (acceso perdido). Cierra sus sesiones
 * abiertas y, con las opciones, reactiva la cuenta o reinicia su verificación en dos pasos.
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password
        {email : Correo del administrador}
        {--password= : Contraseña nueva. Si se omite se pide sin mostrarla}
        {--activate : Reactivar la cuenta si estaba desactivada}
        {--reset-2fa : Desactivar también su verificación en dos pasos}';

    protected $description = 'Restablece la contraseña de un administrador del panel';

    public function handle(): int
    {
        $admin = AdminUser::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if (! $admin) {
            $this->error('No existe un administrador con ese correo.');

            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password === null || $password === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Sin terminal interactiva: indica la contraseña con --password.');

                return self::FAILURE;
            }
            $password = (string) $this->secret('Contraseña nueva');
            if ($password !== (string) $this->secret('Repite la contraseña')) {
                $this->error('Las contraseñas no coinciden.');

                return self::FAILURE;
            }
        }

        $rules = Validator::make(['password' => $password], ['password' => array_merge(['required'], PasswordPolicy::baseRules('admin'))]);
        if ($rules->fails()) {
            $this->error($rules->errors()->first('password'));

            return self::FAILURE;
        }
        try {
            PasswordPolicy::assertComplexity((string) $password, 'admin');
        } catch (ValidationException $e) {
            $this->error($e->errors()['password'][0] ?? 'La contraseña no cumple la política.');

            return self::FAILURE;
        }

        $admin->forceFill(['password' => $password, 'sessions_revoked_at' => now()]);
        if ($this->option('activate')) {
            $admin->is_active = true;
        }
        if ($this->option('reset-2fa')) {
            $admin->forceFill(['totp_secret' => null, 'totp_enabled' => false, 'totp_recovery_codes' => null]);
        }
        $admin->save();

        $this->info("Contraseña restablecida para {$admin->email}; sus sesiones abiertas se cerraron.");
        if (! $admin->is_active) {
            $this->warn('La cuenta sigue desactivada: usa --activate si debe poder entrar.');
        }

        return self::SUCCESS;
    }
}
