<?php

namespace Database\Factories;

use App\Models\SystemParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemParameterFactory extends Factory
{
    protected $model = SystemParameter::class;

    public function definition(): array
    {
        return [
            'admin_password_min_length' => 8,
            'admin_password_require_uppercase' => true,
            'admin_password_require_lowercase' => true,
            'admin_password_require_digit' => true,
            'admin_password_require_symbol' => false,
            'pos_password_min_length' => 4,
            'pos_password_require_uppercase' => false,
            'pos_password_require_lowercase' => false,
            'pos_password_require_digit' => false,
            'pos_password_require_symbol' => false,
            'admin_max_failed_login_attempts' => 5,
            'admin_lockout_minutes' => 15,
            'sync_paused' => false,
            'mail_driver' => 'smtp',
            'mail_from_address' => 'noreply@tapandgo.local',
            'mail_from_name' => 'Tap&Go',
        ];
    }
}
