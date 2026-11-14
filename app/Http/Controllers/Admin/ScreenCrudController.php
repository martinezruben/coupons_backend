<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminActive;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\TopUpRule;
use App\Models\User;
use App\Services\AdminUserGuard;
use App\Support\AdminGridCell;
use App\Support\AdminGridQuery;
use App\Support\AdminRbac;
use App\Support\LocationMapSyncPins;
use App\Support\PasswordPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ScreenCrudController extends Controller
{
    /** @var int Filas por página en los listados CRUD del admin. */
    private const ADMIN_GRID_PER_PAGE = 20;

    private function getScreen(string $screen): array
    {
        $config = config("admin_screens.$screen");
        abort_unless($config && isset($config['model']), 404);

        return $config;
    }

    private function assertScreenWritable(array $cfg): void
    {
        abort_if(! empty($cfg['readonly']), 404);
    }

    private function authorize(string $screen, string $action): void
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        if (AdminRbac::isSuperAdminOnly($screen)) {
            abort_unless(AdminRbac::isSuperAdmin($user), 403);

            return;
        }
        $p = AdminRbac::permissionsForScreen($screen);
        $perm = match ($action) {
            'view' => $p['view'],
            'edit' => $p['edit'],
            'delete' => $p['delete'],
            default => $p['view'],
        };
        abort_unless($user->can($perm), 403);
    }

    public function index(Request $request, string $screen)
    {
        $this->authorize($screen, 'view');
        $cfg = $this->getScreen($screen);
        if (! empty($cfg['route'])) {
            return redirect()->route($cfg['route'], $request->query());
        }
        $model = $cfg['model'];
        $query = $model::query();

        $with = AdminGridCell::eagerRelations($cfg);
        if ($with !== []) {
            $query->with($with);
        }

        if ($screen === 'android-users') {
            $query->selectRaw('users.*, '.AdminGridQuery::androidUsersLastActivitySql().' as last_activity_at');
        }

        if ($screen === 'admin-users') {
            $query->with('roles')->selectRaw("admin_users.*, (SELECT MAX(l.created_at) FROM admin_audit_logs l WHERE l.admin_user_id = admin_users.id AND l.action = 'login') as last_login_at");
        }

        AdminGridQuery::apply($query, $request, $screen, $cfg);

        $items = $query->paginate(self::ADMIN_GRID_PER_PAGE)->withQueryString();

        $p = AdminRbac::permissionsForScreen($screen);
        $user = auth('admin')->user();

        $locationsMapPins = [];
        if ($screen === 'locations') {
            $locationsMapPins = LocationMapSyncPins::forRequest($request, $user->can($p['edit']));
        }

        return view('admin.crud.index', [
            'items' => $items,
            'screen' => $screen,
            'cfg' => $cfg,
            'gridFilterOptions' => AdminGridQuery::filterOptions($cfg),
            'canEdit' => $user->can($p['edit']) && empty($cfg['readonly']),
            'canDelete' => $user->can($p['delete']) && empty($cfg['readonly']) && empty($cfg['disable_delete']),
            'locationsMapPins' => $locationsMapPins,
        ]);
    }

    public function create(string $screen)
    {
        $this->authorize($screen, 'edit');
        $cfg = $this->getScreen($screen);
        $this->assertScreenWritable($cfg);
        abort_if(! empty($cfg['disable_create']), 404);

        return view('admin.crud.form', [
            'item' => null,
            'screen' => $screen,
            'cfg' => $cfg,
            'foreignSelectOptions' => $this->foreignSelectOptionsForForm($cfg),
            'roles' => Role::where('guard_name', 'admin')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, string $screen)
    {
        $this->authorize($screen, 'edit');
        $cfg = $this->getScreen($screen);
        $this->assertScreenWritable($cfg);
        abort_if(! empty($cfg['disable_create']), 404);
        $data = $this->validatedData($request, $cfg, false, $screen);

        $model = $cfg['model'];
        $created = $model::create($data);
        if ($created instanceof AdminUser) {
            $names = array_values($request->input('role_names', []));
            $created->syncRoles($names);
            if ($names !== []) {
                AdminAuditLog::record('updated', 'AdminUser', (string) $created->getKey(), ['roles' => ['', implode(', ', $names)]]);
            }
        }
        if ($screen === 'android-users' && $created instanceof User) {
            $this->syncAndroidUserLocationPivot($created, $data['location_id'] ?? null);
        }

        return redirect()->route('admin.screens.index', $screen)->with('status', 'Creado correctamente.');
    }

    public function edit(string $screen, string $id)
    {
        $this->authorize($screen, 'edit');
        $cfg = $this->getScreen($screen);
        $this->assertScreenWritable($cfg);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);
        if ($model === AdminUser::class) {
            $item->load('roles');
        }
        if ($screen === 'devices' && $item instanceof Device) {
            $item->load('location');
        }
        if ($screen === 'android-users' && $item instanceof User) {
            $item->load('location');
        }

        return view('admin.crud.form', [
            'item' => $item,
            'screen' => $screen,
            'cfg' => $cfg,
            'foreignSelectOptions' => $this->foreignSelectOptionsForForm($cfg),
            'roles' => Role::where('guard_name', 'admin')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, string $screen, string $id)
    {
        $this->authorize($screen, 'edit');
        $cfg = $this->getScreen($screen);
        $this->assertScreenWritable($cfg);
        $model = $cfg['model'];
        $item = $model::findOrFail($id);

        if ($screen === 'devices' && $item instanceof Device) {
            $validated = $request->validate([
                'name' => ['nullable', 'string', 'max:100'],
                'is_enabled' => ['nullable', 'boolean'],
            ]);
            // Habilitar/deshabilitar exige motivo y revoca sesiones: se hace con Bloquear/Reactivar
            if ($request->has('is_enabled') && $request->boolean('is_enabled') !== (bool) $item->is_enabled) {
                throw ValidationException::withMessages([
                    'is_enabled' => 'Para bloquear o reactivar el dispositivo usa los botones de acceso e indica el motivo.',
                ]);
            }

            $item->update(['name' => $validated['name'] ?? null]);

            return redirect()->route('admin.screens.index', $screen)->with('status', 'Actualizado correctamente.');
        }

        $data = $this->validatedData($request, $cfg, true, $screen);

        if ($item instanceof AdminUser) {
            $this->updateAdminUser($request, $item, $data);
        } else {
            $item->update($data);
        }
        if ($screen === 'android-users' && $item instanceof User) {
            $this->syncAndroidUserLocationPivot($item, $data['location_id'] ?? null);
        }

        return redirect()->route('admin.screens.index', $screen)->with('status', 'Actualizado correctamente.');
    }

    /**
     * Edita un usuario administrador: aplica las reglas de protección, sincroniza roles (con rastro en la
     * auditoría) y, si cambia la contraseña, cierra sus otras sesiones.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateAdminUser(Request $request, AdminUser $item, array $data): void
    {
        $actor = auth('admin')->user();
        $newRoles = array_values($request->input('role_names', []));
        $newActive = (bool) ($data['is_active'] ?? false);

        AdminUserGuard::assertChangeAllowed($actor, $item, $newRoles, $newActive);

        $oldRoles = $item->roles->pluck('name')->sort()->values()->all();
        $passwordChanged = ! empty($data['password']);

        $item->update($data);
        $item->syncRoles($newRoles);

        sort($newRoles);
        if ($oldRoles !== $newRoles) {
            AdminAuditLog::record('updated', 'AdminUser', (string) $item->getKey(), ['roles' => [implode(', ', $oldRoles), implode(', ', $newRoles)]]);
        }

        if ($passwordChanged) {
            $item->forceFill(['sessions_revoked_at' => now()])->save();
            if ($actor->is($item)) {
                // Cambió su propia contraseña: se cierran las demás sesiones, no esta
                $request->session()->put(EnsureAdminActive::SESSION_KEY, now()->timestamp + 1);
            }
        }
    }

    /**
     * Toggle rápido de `status` desde el grid.
     * - licenses: ACTIVE ↔ INACTIVE (EXPIRED / REVOKED son inmutables)
     */
    public function toggleStatus(Request $request, string $screen, string $id)
    {
        $this->authorize($screen, 'edit');
        $cfg = $this->getScreen($screen);
        $model = $cfg['model'];

        $item = $model::findOrFail($id);

        if ($screen === 'licenses') {
            $current = $item->status;
            $next = match ($current) {
                'ACTIVE' => 'INACTIVE',
                'INACTIVE' => 'ACTIVE',
                default => $current, // EXPIRED / REVOKED: inmutables vía toggle
            };
            $item->forceFill(['status' => $next])->save();
        }

        return back()->with('status', 'Estado actualizado.');
    }

    public function destroy(string $screen, string $id)
    {
        $this->authorize($screen, 'delete');
        $cfg = $this->getScreen($screen);
        $this->assertScreenWritable($cfg);
        abort_if(! empty($cfg['disable_delete']), 404);
        $model = $cfg['model'];
        $model::findOrFail($id)->delete();

        return back()->with('status', 'Eliminado correctamente.');
    }

    /**
     * @return array<string, Collection<int, array{id: string, label: string}>>
     */
    private function foreignSelectOptionsForForm(array $cfg): array
    {
        $out = [];
        foreach (array_keys($cfg['foreign_labels'] ?? []) as $field) {
            $out[$field] = AdminGridCell::selectOptions($cfg, $field);
        }

        return $out;
    }

    private function syncAndroidUserLocationPivot(User $user, ?string $locationId): void
    {
        if ($locationId !== null && $locationId !== '') {
            $user->locations()->sync([$locationId]);
        } else {
            $user->locations()->detach();
        }
    }

    private function validatedData(Request $request, array $cfg, bool $updating = false, ?string $screen = null): array
    {
        foreach (array_keys($cfg['foreign_labels'] ?? []) as $field) {
            if (! empty($cfg['foreign_labels'][$field]['virtual'])) {
                continue;
            }
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        foreach (['latitude', 'longitude'] as $coord) {
            if (in_array($coord, $cfg['fields'] ?? [], true) && $request->input($coord) === '') {
                $request->merge([$coord => null]);
            }
        }

        if (in_array('barcode', $cfg['fields'] ?? [], true) && $request->input('barcode') === '') {
            $request->merge(['barcode' => null]);
        }

        $rules = [];
        foreach ($cfg['fields'] as $field) {
            if (! empty($cfg['foreign_labels'][$field]['virtual'])) {
                continue;
            }
            if ($field === 'password') {
                if ($screen === 'android-users') {
                    $rules[$field] = array_merge(
                        [$updating ? 'nullable' : 'required'],
                        PasswordPolicy::baseRules('pos')
                    );
                } elseif ($screen === 'admin-users') {
                    $rules[$field] = array_merge(
                        [$updating ? 'nullable' : 'required'],
                        PasswordPolicy::baseRules('admin')
                    );
                } else {
                    $rules[$field] = [$updating ? 'nullable' : 'required', 'string', 'min:8'];
                }
            } elseif (str_ends_with($field, '_at') || in_array($field, ['valid_from', 'valid_to'], true)) {
                $rules[$field] = ['nullable', 'date'];
            } elseif (str_starts_with($field, 'is_')) {
                $rules[$field] = ['nullable', 'boolean'];
            } elseif ($field === 'latitude') {
                $rules[$field] = ['nullable', 'numeric', 'between:-90,90'];
            } elseif ($field === 'longitude') {
                $rules[$field] = ['nullable', 'numeric', 'between:-180,180'];
            } elseif (isset($cfg['foreign_labels'][$field])) {
                $meta = $cfg['foreign_labels'][$field];
                $modelClass = AdminGridCell::relationModel($meta['relation']);
                $m = new $modelClass;
                $rules[$field] = ['nullable', 'uuid', Rule::exists($m->getTable(), $m->getKeyName())];
            } else {
                $rules[$field] = ['nullable'];
            }
        }

        // Columnas NOT NULL sin valor por defecto: obligatorias en el formulario, para
        // responder con un mensaje de validación y no con un error de base de datos
        foreach ($this->requiredColumns($cfg['model']) as $column) {
            if ($column !== 'password' && ($rules[$column][0] ?? null) === 'nullable') {
                $rules[$column][0] = 'required';
            }
        }

        if ($screen === 'licenses') {
            $rules['valid_from'] = ['required', 'date'];
            $rules['valid_to'] = ['required', 'date', 'after_or_equal:valid_from'];
        }

        if ($screen === 'android-users') {
            $roleKeys = array_keys($cfg['select_options']['role'] ?? [
                'CASHIER' => '',
                'MANAGER' => '',
                'ADMIN' => '',
            ]);
            $usernameUnique = Rule::unique('users', 'username');
            if ($updating && $request->route('id')) {
                $usernameUnique = $usernameUnique->ignore($request->route('id'), 'id');
            }
            $rules['username'] = ['required', 'string', 'max:50', $usernameUnique];
            $rules['full_name'] = ['nullable', 'string', 'max:100'];
            $rules['role'] = ['required', Rule::in($roleKeys)];
            $rules['pin4'] = ['nullable', 'digits:4'];
        }

        if ($screen === 'admin-users') {
            if (is_string($request->input('email'))) {
                $request->merge(['email' => strtolower(trim($request->input('email')))]);
            }
            $emailUnique = Rule::unique('admin_users', 'email');
            if ($updating && $request->route('id')) {
                $emailUnique = $emailUnique->ignore($request->route('id'));
            }
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['email'] = ['required', 'email:rfc', 'max:255', 'lowercase', $emailUnique];
            $rules['role_names'] = ['nullable', 'array'];
            $rules['role_names.*'] = ['string', Rule::exists('roles', 'name')->where('guard_name', 'admin')];
        }

        if ($screen === 'customers') {
            $documentUnique = Rule::unique('customers', 'document');
            if ($updating && $request->route('id')) {
                $documentUnique = $documentUnique->ignore($request->route('id'), 'id');
            }
            $rules['name'] = ['required', 'string', 'max:150'];
            $rules['document'] = ['nullable', 'string', 'max:50', $documentUnique];
            $rules['email'] = ['nullable', 'email', 'max:150'];
            $rules['phone'] = ['nullable', 'string', 'max:50'];
            $rules['notes'] = ['nullable', 'string', 'max:1000'];
        }

        if ($screen === 'topup-rules') {
            $rules['name'] = ['required', 'string', 'max:100'];
            $rules['type'] = ['required', Rule::in(TopUpRule::TYPES)];
            $rules['coupon_type'] = ['nullable', Rule::in(['QRCODE', 'CODBAR'])];
            $rules['weekdays'] = ['nullable', 'regex:/^[1-7](,[1-7]){0,6}$/'];
            $rules['max_daily_amount'] = ['nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'];
        }

        // Etiquetas de la pantalla como nombres de atributo en los mensajes de error
        $data = $request->validate($rules, [], AdminGridCell::validationAttributes($cfg));

        if ($screen === 'topup-rules') {
            $required = ['location' => 'location_id', 'weekday' => 'weekdays', 'daily_amount' => 'max_daily_amount'][$data['type']];
            if (empty($data[$required])) {
                throw ValidationException::withMessages([
                    $required => 'Este campo es obligatorio para el tipo de regla elegido.',
                ]);
            }
            // Cada tipo guarda solo sus propios datos
            $data = array_merge(['location_id' => null, 'weekdays' => null, 'max_daily_amount' => null], $data);
            foreach (array_diff(['location_id', 'weekdays', 'max_daily_amount'], [$required]) as $unused) {
                $data[$unused] = null;
            }
        }

        // Los roles se sincronizan aparte (no son columnas de admin_users)
        unset($data['role_names']);

        if (isset($data['password']) && $data['password'] !== '') {
            if ($screen === 'admin-users') {
                PasswordPolicy::assertComplexity($data['password'], 'admin');
            } elseif ($screen === 'android-users') {
                PasswordPolicy::assertComplexity($data['password'], 'pos');
            }
        }

        if ($screen === 'android-users' && isset($data['pin4']) && $data['pin4'] !== '' && $data['pin4'] !== null) {
            if (User::isWeakPin4((string) $data['pin4'])) {
                throw ValidationException::withMessages([
                    'pin4' => 'Ese PIN es demasiado común o predecible. Elige otro de 4 dígitos.',
                ]);
            }
            $data['pin4_sha384'] = User::pin4Sha384FromPlain((string) $data['pin4']);
            $data['pin4_enc'] = $data['pin4']; // cast encrypted del modelo
        }
        unset($data['pin4']);

        foreach ($cfg['fields'] as $field) {
            if (str_starts_with($field, 'is_')) {
                $data[$field] = $request->boolean($field);
            }
        }

        foreach (array_keys($cfg['foreign_labels'] ?? []) as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        if (array_key_exists('password', $data)) {
            if (empty($data['password'])) {
                unset($data['password']);
            } elseif ($screen !== 'android-users') {
                $data['password'] = Hash::make($data['password']);
            }
            // android-users: se pasa en claro; User::password() guarda el hash bcrypt y el hash del PIN (SHA-384)
        }

        if (($cfg['model'] ?? '') === Role::class || ($cfg['model'] ?? '') === Permission::class) {
            $data['guard_name'] = 'admin';
        }

        return $data;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private function requiredColumns(string $modelClass): array
    {
        $model = new $modelClass;

        return collect(Schema::getColumns($model->getTable()))
            ->filter(fn (array $c) => ! $c['nullable'] && $c['default'] === null && ! $c['auto_increment'] && $c['name'] !== $model->getKeyName())
            ->pluck('name')
            ->all();
    }
}
