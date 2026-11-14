<?php

namespace App\Models;

use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'admin';

    protected $fillable = ['name', 'email', 'password', 'is_active', 'customer_scope'];

    protected $hidden = ['password', 'remember_token', 'totp_secret', 'totp_recovery_codes'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'sessions_revoked_at' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        foreach (config('admin.super_admin_roles', []) as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'admin_user_customers', 'admin_user_id', 'customer_id');
    }

    /** ¿Solo opera con los clientes que se le asignaron? El super-admin nunca está restringido. */
    public function isCustomerRestricted(): bool
    {
        return ($this->customer_scope ?? 'all') === 'selected' && ! $this->isSuperAdmin();
    }

    /** @return list<string>|null IDs de clientes permitidos; null = todos */
    public function allowedCustomerIds(): ?array
    {
        if (! $this->isCustomerRestricted()) {
            return null;
        }

        return $this->customers()->pluck('customers.id')->map(fn ($id) => (string) $id)->all();
    }
}
