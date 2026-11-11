<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'username' => fake()->unique()->userName(),
            'full_name' => fake()->name(),
            'role' => 'CASHIER',
            'is_active' => true,
            'password' => static::$password ??= Hash::make('password'),
            'pin_sha384' => User::pinSha384FromPlain('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
