<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Lista los usuarios POS cuyo PIN no sirve sin conexión: la app valida el PIN comparando el SHA-384 de
 * lo tecleado con `pin`; si el usuario no tiene `pin_sha384` el servidor envía otra cosa y su PIN
 * correcto falla. No se puede corregir solo (la contraseña original no es recuperable): hay que
 * volver a guardar su contraseña desde el panel (Usuarios POS).
 */
class AuditPosUserPins extends Command
{
    protected $signature = 'pos:audit-pins
        {--all : Incluir también usuarios inactivos}
        {--strict : Terminar con código 1 si hay usuarios afectados (para scripts y CI)}';

    protected $description = 'Lista los usuarios POS sin hash de PIN válido para uso sin conexión';

    public function handle(): int
    {
        $users = User::query()
            ->withoutOfflinePin()
            ->reachingDevices()
            ->when(! $this->option('all'), fn ($q) => $q->where('users.is_active', true))
            ->with(['locations:id,name', 'location:id,name'])
            ->orderBy('username')
            ->get();

        if ($users->isEmpty()) {
            $this->info('Todos los usuarios POS tienen un PIN válido para uso sin conexión.');

            return self::SUCCESS;
        }

        $this->table(
            ['Usuario', 'Nombre', 'Rol', 'Activo', 'Localidades'],
            $users->map(fn (User $u) => [
                $u->username,
                $u->full_name ?? '—',
                $u->role,
                $u->is_active ? 'Sí' : 'No',
                $u->locations->pluck('name')->push($u->location?->name)->filter()->unique()->implode(', ') ?: '—',
            ])->all(),
        );

        $this->warn($users->count().' usuario(s) no podrán entrar con su PIN sin conexión.');
        $this->line('Solución: guardar de nuevo su contraseña en el panel (Usuarios POS → editar → Contraseña).');

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
