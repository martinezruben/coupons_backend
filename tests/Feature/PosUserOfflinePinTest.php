<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosUserOfflinePinTest extends TestCase
{
    use RefreshDatabase;

    /** Crea un usuario "legacy": con contraseña pero sin hash de PIN (como los anteriores a la columna). */
    private function legacyUser(array $attrs = [], ?Location $location = null): User
    {
        $user = User::factory()->create($attrs + ['username' => fake()->unique()->userName()]);
        User::query()->whereKey($user->id)->update(['pin_sha384' => null]);
        if ($location) {
            $user->locations()->attach($location->id);
        }

        return $user->fresh();
    }

    private function adminWith(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    // ---- Punto 3: el modelo garantiza que contraseña y hash del PIN no se desalineen

    public function test_assigning_a_plain_password_always_stores_the_pin_hash(): void
    {
        $user = User::create(['username' => 'cajero1', 'password' => 'Secreta123!', 'role' => 'CASHIER', 'is_active' => true]);

        $this->assertTrue(Hash::check('Secreta123!', $user->password));
        $this->assertSame(hash('sha384', 'Secreta123!'), $user->pin_sha384);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{96}$/', $user->pin_sha384);
        $this->assertTrue($user->hasOfflinePin());
    }

    public function test_pin_hash_uses_the_trimmed_password(): void
    {
        $user = User::create(['username' => 'cajero2', 'password' => '  Con espacios 1!  ', 'role' => 'CASHIER']);

        $this->assertSame(hash('sha384', 'Con espacios 1!'), $user->pin_sha384);
    }

    public function test_pin_hash_covers_non_ascii_passwords_as_utf8(): void
    {
        $user = User::create(['username' => 'cajero3', 'password' => 'Contraseña-ñ-9!', 'role' => 'CASHIER']);

        $this->assertSame(hash('sha384', 'Contraseña-ñ-9!'), $user->pin_sha384);
    }

    public function test_changing_the_password_updates_the_pin_hash_and_other_edits_do_not(): void
    {
        $user = User::create(['username' => 'cajero4', 'password' => 'Primera123!', 'role' => 'CASHIER']);
        $first = $user->pin_sha384;

        $user->update(['full_name' => 'Otro nombre']);
        $this->assertSame($first, $user->fresh()->pin_sha384);

        $user->update(['password' => 'Segunda456!']);
        $fresh = $user->fresh();
        $this->assertNotSame($first, $fresh->pin_sha384);
        $this->assertSame(hash('sha384', 'Segunda456!'), $fresh->pin_sha384);
        $this->assertTrue(Hash::check('Segunda456!', $fresh->password));
    }

    public function test_an_already_hashed_password_is_stored_as_is_and_never_hashed_again_into_the_pin(): void
    {
        $bcrypt = Hash::make('Original789!');
        $user = User::create(['username' => 'cajero5', 'password' => $bcrypt, 'role' => 'CASHIER']);

        $this->assertSame($bcrypt, $user->password);
        $this->assertNull($user->pin_sha384);            // no se inventa un hash del hash
        $this->assertFalse($user->hasOfflinePin());
    }

    public function test_panel_created_and_edited_users_get_a_valid_pin_hash(): void
    {
        $admin = $this->adminWith('android_users.view', 'android_users.edit');
        $this->actingAs($admin, 'admin')->post(route('admin.screens.store', 'android-users'), [
            'username' => 'desde_panel', 'full_name' => 'Panel', 'role' => 'CASHIER', 'is_active' => '1', 'password' => 'Panel12345!',
        ])->assertSessionHasNoErrors();

        $user = User::where('username', 'desde_panel')->first();
        $this->assertSame(hash('sha384', 'Panel12345!'), $user->pin_sha384);
        $this->assertTrue(Hash::check('Panel12345!', $user->password));

        $this->actingAs($admin, 'admin')->put(route('admin.screens.update', ['android-users', $user->id]), [
            'username' => 'desde_panel', 'full_name' => 'Panel', 'role' => 'CASHIER', 'is_active' => '1', 'password' => 'Cambiada678!',
        ])->assertSessionHasNoErrors();

        $this->assertSame(hash('sha384', 'Cambiada678!'), $user->fresh()->pin_sha384);
    }

    // ---- Punto 1: detección y visibilidad

    public function test_scope_finds_missing_and_malformed_hashes_only(): void
    {
        $ok = User::factory()->create();                      // la fábrica sí guarda el hash
        $missing = $this->legacyUser();
        $malformed = User::factory()->create();
        User::query()->whereKey($malformed->id)->update(['pin_sha384' => 'abc']);

        $ids = User::query()->withoutOfflinePin()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$missing->id, $malformed->id], $ids);
        $this->assertNotContains($ok->id, $ids);
    }

    public function test_audit_command_lists_only_active_users_that_reach_devices(): void
    {
        $loc = Location::factory()->create(['name' => 'Sede Centro']);
        $this->legacyUser(['username' => 'afectado'], $loc);
        $this->legacyUser(['username' => 'inactivo', 'is_active' => false], $loc);
        $this->legacyUser(['username' => 'sin_localidad']);
        $this->legacyUser(['username' => 'por_columna', 'location_id' => $loc->id]);
        $ok = User::factory()->create(['username' => 'bien']);
        $ok->locations()->attach($loc->id);

        $this->artisan('pos:audit-pins')
            ->expectsOutputToContain('afectado')
            ->expectsOutputToContain('por_columna')
            ->expectsOutputToContain('2 usuario(s)')
            ->doesntExpectOutputToContain('inactivo')
            ->doesntExpectOutputToContain('sin_localidad')
            ->assertSuccessful();

        $this->artisan('pos:audit-pins', ['--all' => true])
            ->expectsOutputToContain('inactivo')
            ->assertSuccessful();
    }

    public function test_audit_command_strict_mode_fails_only_when_there_are_affected_users(): void
    {
        $loc = Location::factory()->create();
        $good = User::factory()->create();
        $good->locations()->attach($loc->id);

        $this->artisan('pos:audit-pins', ['--strict' => true])
            ->expectsOutputToContain('Todos los usuarios POS')
            ->assertSuccessful();

        $this->legacyUser([], $loc);
        $this->artisan('pos:audit-pins', ['--strict' => true])->assertFailed();
    }

    public function test_android_users_grid_flags_and_filters_users_without_pin(): void
    {
        $admin = $this->adminWith('android_users.view');
        $this->legacyUser(['username' => 'usuario_roto']);
        User::factory()->create(['username' => 'usuario_sano']);

        $this->actingAs($admin, 'admin')->get(route('admin.screens.index', 'android-users'))
            ->assertOk()->assertSee('usuario_roto')->assertSee('usuario_sano')
            ->assertSee('Restablecer contraseña')->assertSee('✓ Listo');

        $this->actingAs($admin, 'admin')->get(route('admin.screens.index', ['android-users', 'filter' => ['pin' => '1']]))
            ->assertOk()->assertSee('usuario_roto')->assertDontSee('usuario_sano');
    }
}
