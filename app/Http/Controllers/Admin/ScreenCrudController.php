<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdminActive;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Family;
use App\Models\Location;
use App\Models\User;
use App\Services\AdminUserGuard;
use App\Services\ImageThumbnailService;
use App\Support\AdminGridCell;
use App\Support\AdminGridQuery;
use App\Support\AdminRbac;
use App\Support\LocationMapSyncPins;
use App\Support\PasswordPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

        if ($screen === 'transactions' || $screen === 'transactions-report') {
            $query->withCount('items');
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

        $transactionExportLocations = [];
        if ($screen === 'transactions' || $screen === 'transactions-report') {
            $transactionExportLocations = Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static function (Location $loc): array {
                    return [
                        'id' => (string) $loc->getKey(),
                        'name' => $loc->name,
                    ];
                })
                ->values()
                ->all();
        }

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
            'showTransactionExcelExport' => $screen === 'transactions' && $user->can($p['view']),
            'showTransactionReportExport' => $screen === 'transactions-report' && $user->can($p['view']),
            'transactionExportLocations' => $transactionExportLocations,
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

        if ($screen === 'families') {
            $this->applyImageUpload($request, $data, null, 'families');
        } elseif ($screen === 'products') {
            $this->applyImageUpload($request, $data, null, 'products');
        }

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

        if ($screen === 'families' && $item instanceof Family) {
            $this->applyImageUpload($request, $data, $item, 'families');
        } elseif ($screen === 'products') {
            $this->applyImageUpload($request, $data, $item, 'products');
        }

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
     * - transactions: PAID ↔ VOIDED
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
        } elseif ($screen === 'transactions') {
            $current = $item->status;
            $next = $current === 'PAID' ? 'VOIDED' : 'PAID';
            $item->forceFill(['status' => $next])->save();

            // Acción financiera del panel: registrar manualmente (Transaction no está en el observer
            // para no ensuciar el log con cada sync del POS)
            AdminAuditLog::record('updated', 'Transaction', (string) $item->getKey(), [
                'status' => [$current, $next],
            ]);
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

    /**
     * Subida de imagen genérica para familias y productos.
     * Usa el disco «public» y guarda la URL relativa en `image_url`.
     *
     * @param  array<string,mixed>  $data  Datos validados (se muta para set image_url)
     */
    private function applyImageUpload(Request $request, array &$data, ?Model $existing, string $folder): void
    {
        $request->validate([
            ($folder === 'families' ? 'family_image' : 'product_image') => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120'],
        ], [
            ($folder === 'families' ? 'family_image' : 'product_image').'.image' => 'El archivo debe ser una imagen.',
        ]);

        $fieldName = $folder === 'families' ? 'family_image' : 'product_image';

        // No fallar en silencio: si el navegador envió un archivo que PHP rechazó
        // (upload_max_filesize) o llegó corrupto, informarlo claramente.
        if ($request->files->has($fieldName) && ! $request->file($fieldName)?->isValid()) {
            throw ValidationException::withMessages([
                $fieldName => 'La imagen no se pudo subir (puede superar el límite del servidor). Intenta con una de menor tamaño.',
            ]);
        }

        if ($request->hasFile($fieldName)) {
            if ($existing?->image_url) {
                $this->deleteStoredPublicFile($existing->image_url);
                ImageThumbnailService::deleteFor($existing->image_url);
            }
            $path = $request->file($fieldName)->store($folder, 'public');
            if ($path === false || $path === null || $path === '') {
                // store() retorna false (config throw=false) cuando el disco no
                // es escribible: sin este check quedaría una URL sin archivo.
                throw ValidationException::withMessages([
                    $fieldName => 'No se pudo guardar la imagen en el almacenamiento del servidor (permisos de escritura).',
                ]);
            }
            $data['image_url'] = Storage::disk('public')->url($path);

            // Comprimir el original en el lugar (máx 1600px): el fallback del
            // POS cuando no hay miniatura nunca entrega un archivo de MBs.
            ImageThumbnailService::compressOriginal($path);

            if (ImageThumbnailService::generate($path) === null) {
                // La imagen original se guardó; la miniatura no (ej. GD sin WebP en el host)
                Log::warning('No se pudo generar miniatura', ['path' => $path]);
            }

            return;
        }

        if ($request->boolean('remove_image') && $existing?->image_url) {
            $this->deleteStoredPublicFile($existing->image_url);
            ImageThumbnailService::deleteFor($existing->image_url);
            $data['image_url'] = null;
        }
    }

    private function deleteStoredPublicFile(?string $publicUrl): void
    {
        if ($publicUrl === null || $publicUrl === '') {
            return;
        }
        if (preg_match('#/storage/(.+)$#', $publicUrl, $m)) {
            Storage::disk('public')->delete($m[1]);
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

        if (in_array('cost', $cfg['fields'] ?? [], true) && $request->input('cost') === '') {
            $request->merge(['cost' => null]);
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
            } elseif (str_ends_with($field, '_at') || in_array($field, ['valid_from', 'valid_to', 'start_time', 'end_time', 'occurred_at', 'synced_at'], true)) {
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
            } elseif ($screen === 'products' && $field === 'cost') {
                $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];
            } elseif ($screen === 'products' && $field === 'barcode') {
                $rule = Rule::unique('products', 'barcode');
                if ($updating && $request->route('id')) {
                    $rule = $rule->ignore($request->route('id'));
                }
                $rules[$field] = ['nullable', 'string', 'max:64', $rule];
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

        if ($screen === 'families') {
            $rules['description'] = ['nullable', 'string', 'max:255'];
        }

        if ($screen === 'promotions') {
            $rules['name'] = ['required', 'string', 'max:120'];
            $rules['description'] = ['nullable', 'string', 'max:255'];
            $rules['value'] = ['nullable', 'numeric', 'min:0'];
            $rules['buy_qty'] = ['nullable', 'numeric', 'min:1'];
            $rules['pay_qty'] = ['nullable', 'numeric', 'min:1'];
            $rules['starts_at'] = ['nullable', 'date'];
            $rules['ends_at'] = ['nullable', 'date', 'after_or_equal:starts_at'];
            $rules['product_id'] = ['nullable', 'uuid', Rule::exists('products', 'id')];
            $rules['subfamily_id'] = ['nullable', 'uuid', Rule::exists('subfamilies', 'id')];
            $rules['family_id'] = ['nullable', 'uuid', Rule::exists('families', 'id')];
        }

        // Etiquetas de la pantalla como nombres de atributo en los mensajes de error
        $data = $request->validate($rules, [], AdminGridCell::validationAttributes($cfg));

        if ($screen === 'families') {
            $rules['description'] = ['nullable', 'string', 'max:255'];
        }

        if ($screen === 'promotions') {
            $this->validatePromotionBusinessRules($request, $data);
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
     * Reglas de negocio de promociones (tras la validación base de campos).
     *
     * @param  array<string, mixed>  $data
     */
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

    private function validatePromotionBusinessRules(Request $request, array $data): void
    {
        $fail = function (string $field, string $message): void {
            throw ValidationException::withMessages([$field => $message]);
        };

        // PERCENT: valor expresado en %
        if (($data['type'] ?? null) === 'PERCENT' && (float) ($data['value'] ?? 0) > 100) {
            $fail('value', 'El porcentaje no puede superar 100.');
        }

        // BUNDLE no usa «valor» (usa cantidades); el resto exige valor > 0
        if (($data['type'] ?? null) === 'BUNDLE') {
            $data['value'] = 0;
        } elseif (! isset($data['value']) || (float) $data['value'] <= 0) {
            $fail('value', 'El valor de la promoción es requerido y debe ser mayor que cero.');
        }

        $scopeCount = collect(['product_id', 'subfamily_id', 'family_id'])
            ->filter(fn ($f) => ! empty($data[$f]))
            ->count();

        if ($scopeCount > 1) {
            $fail('family_id', 'La promoción debe aplicar a un solo objetivo: producto, subfamilia o familia.');
        }

        if (in_array($data['type'] ?? null, ['PRICE', 'BUNDLE'], true) && $scopeCount === 0) {
            $fail('family_id', 'Este tipo de promoción requiere definir un producto, subfamilia o familia (no puede ser global).');
        }

        if (($data['type'] ?? null) === 'BUNDLE') {
            $buy = isset($data['buy_qty']) ? (float) $data['buy_qty'] : 0.0;
            $pay = isset($data['pay_qty']) ? (float) $data['pay_qty'] : 0.0;
            if ($buy < 1 || $pay < 1 || floor($buy) != $buy || floor($pay) != $pay || $pay >= $buy) {
                $fail('buy_qty', 'Para «lleva N paga M» ambas cantidades deben ser enteros ≥ 1 y «lleva» mayor que «paga» (ej. 2x1).');
            }
        }

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && strtotime((string) $data['ends_at']) < strtotime((string) $data['starts_at'])) {
            $fail('ends_at', 'La fecha final no puede ser anterior a la inicial.');
        }
    }
}
