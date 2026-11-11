<?php

namespace App\Models;

use App\Models\Traits\HasUuidPrimaryKey;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuidPrimaryKey, Notifiable, SoftDeletes;

    protected $fillable = [
        'username',
        'password',
        'pin_sha384',
        'pin4_sha384',
        'pin4_enc',
        'full_name',
        'role',
        'is_active',
        'location_id',
    ];

    protected $hidden = ['password', 'remember_token', 'pin_sha384', 'pin4_sha384', 'pin4_enc'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pin4_enc' => 'encrypted',
        ];
    }

    /**
     * La contraseña del usuario POS es también su PIN de acceso sin conexión: la app compara el
     * SHA-384 de lo tecleado contra `pin_sha384`. Por eso asignar una contraseña en claro guarda
     * SIEMPRE las dos cosas (hash bcrypt + hash del PIN) y no hay forma de dejarlas desalineadas.
     *
     * Si el valor ya viene hasheado (factories, seeders, restauraciones) solo se guarda tal cual:
     * el texto en claro no se puede recuperar, y `pin_sha384` queda como esté.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): array {
                if ($value === null || $value === '' || Hash::isHashed($value)) {
                    return ['password' => $value];
                }

                return [
                    'password' => Hash::make($value),
                    'pin_sha384' => self::pinSha384FromPlain($value),
                ];
            },
        );
    }

    /** ¿Tiene un hash de PIN usable por la app sin conexión (SHA-384 hexadecimal de 96 caracteres)? */
    public function hasOfflinePin(): bool
    {
        return is_string($this->pin_sha384) && preg_match('/^[0-9a-f]{96}$/', $this->pin_sha384) === 1;
    }

    /**
     * Usuarios cuyo PIN no sirve sin conexión: sin `pin_sha384` (o con uno mal formado). Para ellos el
     * pull envía el SHA-384 del hash bcrypt, que nunca coincide con lo que teclea el usuario.
     */
    public function scopeWithoutOfflinePin(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('users.pin_sha384')->orWhereRaw('LENGTH(users.pin_sha384) <> 96');
        });
    }

    /** Usuarios que llegan a las tablets: asignados a alguna localidad (pivote o columna). */
    public function scopeReachingDevices(Builder $query, ?string $locationId = null): Builder
    {
        return $query->where(function (Builder $q) use ($locationId): void {
            $q->whereHas('locations', fn ($rel) => $locationId ? $rel->where('locations.id', $locationId) : $rel)
                ->orWhere(fn ($w) => $locationId ? $w->where('users.location_id', $locationId) : $w->whereNotNull('users.location_id'));
        });
    }

    /** PIN de 4 dígitos en claro si está almacenado cifrado; null en caso contrario. */
    public function pin4Plain(): ?string
    {
        return $this->pin4_enc !== null ? (string) $this->pin4_enc : null;
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'user_locations')->withTimestamps();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * SHA-384 hexadecimal (96 chars) del PIN/contraseña en claro, con trim.
     * La app Android debe aplicar la misma normalización al validar en local.
     */
    public static function pinSha384FromPlain(string $plain): string
    {
        return hash('sha384', trim($plain));
    }

    /**
     * SHA-384 hexadecimal del PIN de 4 dígitos en claro (trim).
     * Verificación offline en el dispositivo; el servidor nunca lo guarda en claro.
     */
    public static function pin4Sha384FromPlain(string $plain): string
    {
        return hash('sha384', trim($plain));
    }

    /** PINs de 4 dígitos demasiado comunes/degutes para permitirlos. */
    public static function isWeakPin4(string $pin): bool
    {
        if (preg_match('/^(\d)\1{3}$/', $pin)) {
            return true; // 0000, 1111, …
        }
        if (preg_match('/^(0123|1234|2345|3456|4567|5678|6789|9876|8765|4321)$/', $pin)) {
            return true; // secuencias
        }

        return in_array($pin, ['1004', '2000', '2580', '1122', '1313'], true);
    }
}
