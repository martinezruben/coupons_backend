<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponAuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'], $attrs));
    }

    public function test_login_accepts_email_field_and_returns_an_app_compatible_payload(): void
    {
        $user = $this->user();

        $res = $this->postJson('/api/login', ['email' => 'CAJA@example.com', 'password' => 'Correct-Horse-9!'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'caja@example.com')
            ->assertJsonPath('user.admin', 0)
            ->assertJsonMissingPath('user.password');

        // La app Android deserializa `id` como Int.
        $this->assertIsInt($res->json('user.id'));
        $this->assertSame($user->apiId(), $res->json('user.id'));
        $this->assertLessThan(2 ** 31, $res->json('user.id'));
    }

    public function test_imported_users_keep_their_legacy_numeric_id(): void
    {
        $user = $this->user(['legacy_user_id' => 42]);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!'])
            ->assertOk()->assertJsonPath('user.id', 42);
    }

    public function test_token_has_role_abilities_and_expires(): void
    {
        $user = $this->user(['role' => 'MANAGER']);

        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!'])->assertOk();

        $token = $user->tokens()->first();
        $this->assertSame(['coupon:read', 'coupon:redeem', 'coupon:reverse'], $token->abilities);
        $this->assertNotNull($token->expires_at);
    }

    public function test_wrong_password_unknown_user_and_inactive_user_look_identical(): void
    {
        $this->user();
        $this->user(['username' => 'baja@example.com', 'is_active' => false]);

        $responses = [
            $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'mala-mala-mala']),
            $this->postJson('/api/login', ['username' => 'nadie@example.com', 'password' => 'mala-mala-mala']),
            $this->postJson('/api/login', ['username' => 'baja@example.com', 'password' => 'Correct-Horse-9!']),
        ];

        foreach ($responses as $res) {
            $res->assertStatus(401);
            $this->assertSame($responses[0]->json(), $res->json());
        }
    }

    public function test_login_is_rate_limited(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'mala-mala-mala'])->assertStatus(401);
        }

        $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'])->assertStatus(429);
    }

    public function test_logout_only_revokes_the_current_device(): void
    {
        $user = $this->user();
        $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!', 'device_name' => 'a']);
        $tokenB = $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!', 'device_name' => 'b'])->json('token');

        $this->withToken($tokenB)->postJson('/api/logout')->assertOk();

        $this->assertSame(['a'], $user->tokens()->pluck('name')->all());
    }

    public function test_profile_returns_the_authenticated_user(): void
    {
        $user = $this->user(['role' => 'ADMIN']);
        $token = $this->postJson('/api/login', ['username' => $user->username, 'password' => 'Correct-Horse-9!'])->json('token');

        $this->withToken($token)->getJson('/api/profile')->assertOk()->assertJsonPath('user.admin', 1);
    }
}
