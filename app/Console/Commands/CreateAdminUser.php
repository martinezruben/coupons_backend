<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Crea un usuario administrador del panel desde la consola. Sirve para el primer super-admin de una
 * instalación nueva (sin seeders) y como vía de recuperación si se pierde el acceso.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
        {email : Correo con el que iniciará sesión}
        {--name= : Nombre (por defecto, la parte local del correo)}
        {--role=super-admin : Rol a asignar (debe existir; el de super-admin se crea si falta)}
        {--password= : Contraseña. Si se omite se pide sin mostrarla (recomendado: --password queda en el historial de la consola)}';

    protected $description = 'Crea un usuario administrador del panel (por defecto, super-admin)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $roleName = (string) $this->option('role');

        $check = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255', 'unique:admin_users,email']], [
            'email.unique' => 'Ya existe un administrador con ese correo.',
        ]);
        if ($check->fails()) {
            $this->error($check->errors()->first('email'));

            return self::FAILURE;
        }

        $role = $this->resolveRole($roleName);
        if (! $role) {
            $this->error("El rol «{$roleName}» no existe. Créalo en el panel (Sistema → Roles) o usa --role=super-admin.");

            return self::FAILURE;
        }

        $password = $this->readPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $admin = AdminUser::create([
            'name' => $this->option('name') ?: ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ]);
        $admin->assignRole($role);

        $this->info("Administrador creado: {$admin->email} (rol {$role->name}).");
        $this->line('Entra en /admin/login. Si quieres, activa la verificación en dos pasos desde el candado de la barra superior.');

        return self::SUCCESS;
    }

    private function resolveRole(string $name): ?Role
    {
        if (in_array($name, config('admin.super_admin_roles', []), true)) {
            return Role::findOrCreate($name, 'admin');
        }

        return Role::query()->where('guard_name', 'admin')->where('name', $name)->first();
    }

    private function readPassword(): ?string
    {
        $password = $this->option('password');

        if ($password === null || $password === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Sin terminal interactiva: indica la contraseña con --password.');

                return null;
            }
            $password = (string) $this->secret('Contraseña');
            if ($password !== (string) $this->secret('Repite la contraseña')) {
                $this->error('Las contraseñas no coinciden.');

                return null;
            }
        }

        return $this->validPassword((string) $password);
    }

    private function validPassword(string $password): ?string
    {
        $rules = Validator::make(['password' => $password], ['password' => array_merge(['required'], PasswordPolicy::baseRules('admin'))]);
        if ($rules->fails()) {
            $this->error($rules->errors()->first('password'));

            return null;
        }

        try {
            PasswordPolicy::assertComplexity($password, 'admin');
        } catch (ValidationException $e) {
            $this->error($e->errors()['password'][0] ?? 'La contraseña no cumple la política.');

            return null;
        }

        return $password;
    }
}
