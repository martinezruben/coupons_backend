<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemParameter extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_password_min_length',
        'admin_password_require_uppercase',
        'admin_password_require_lowercase',
        'admin_password_require_digit',
        'admin_password_require_symbol',
        'pos_password_min_length',
        'pos_password_require_uppercase',
        'pos_password_require_lowercase',
        'pos_password_require_digit',
        'pos_password_require_symbol',
        'admin_max_failed_login_attempts',
        'admin_lockout_minutes',
        'sync_paused',
        'min_app_version',
        'mail_driver',
        'mail_from_address',
        'mail_from_name',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'office365_tenant_id',
        'office365_client_id',
        'office365_client_secret',
        'office365_scopes',
        'contingency_enabled',
        'contingency_email_list',
        'contingency_resend_hours',
    ];

    protected function casts(): array
    {
        return [
            'admin_password_require_uppercase' => 'boolean',
            'admin_password_require_lowercase' => 'boolean',
            'admin_password_require_digit' => 'boolean',
            'admin_password_require_symbol' => 'boolean',
            'pos_password_require_uppercase' => 'boolean',
            'pos_password_require_lowercase' => 'boolean',
            'pos_password_require_digit' => 'boolean',
            'pos_password_require_symbol' => 'boolean',
            'sync_paused' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_password' => 'encrypted',
            'office365_client_secret' => 'encrypted',
            'office365_scopes' => 'json',
            'contingency_enabled' => 'boolean',
            'contingency_email_list' => 'json',
            'contingency_resend_hours' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }
}
