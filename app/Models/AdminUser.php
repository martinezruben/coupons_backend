<?php

namespace App\Models;

use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'admin';

    protected $fillable = ['name', 'email', 'password', 'is_active'];

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
}
