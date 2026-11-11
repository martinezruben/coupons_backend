<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Subfamily;
use App\Models\SyncLog;
use App\Models\SyncState;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use App\Services\ImageThumbnailService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class SyncController extends Controller
{
    /** Orden de avance del estado de una transacción; un reintento viejo no puede retroceder. */
    private const STATUS_RANK = ['PENDING' => 0, 'PAID' => 1, 'VOIDED' => 2];

    /**
     * Cada transacción: `user_id` = UUID de un usuario POS existente (`users.id`); `status` ∈ PENDING, PAID, VOIDED;
     * `shift_id` = identificador de turno enviado por el cliente (texto libre, opcional), sin validar contra `shifts`.
     * `occurred_at` en ISO 8601 o timestamp Unix (segundos o milisegundos), igual que `lastSyncTimestamp` en pull.
     */
    public function push(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $startedAt = now();

        try {
            return $this->storePush($request, $device, $startedAt);
        } catch (Throwable $e) {
            $this->recordPushFailure($device, $startedAt, $e);

            throw $e;
        }
    }

    private function storePush(Request $request, Device $device, Carbon $startedAt): JsonResponse
    {
        $payload = $request->validate([
            'transactions' => ['required', 'array'],
            'transactions.*.external_id' => ['required', 'string'],
            'transactions.*.shift_id' => ['nullable', 'string', 'max:100'],
            'transactions.*.user_id' => ['required', 'uuid', Rule::exists('users', 'id')],
            'transactions.*.turn_number' => ['required', 'integer'],
            'transactions.*.status' => ['required', 'string', Rule::in(['PENDING', 'PAID', 'VOIDED'])],
            'transactions.*.total' => ['required', 'numeric'],
            'transactions.*.occurred_at' => ['required'],
            'transactions.*.items' => ['array'],
            'transactions.*.items.*.product_id' => ['nullable', 'string'],
            'transactions.*.items.*.product_name' => ['nullable', 'string'],
            'transactions.*.items.*.product_sku' => ['nullable', 'string'],
            'transactions.*.items.*.qty' => ['nullable', 'numeric'],
            'transactions.*.items.*.unit_price' => ['nullable', 'numeric'],
            'transactions.*.items.*.discount' => ['nullable', 'numeric'],
            'transactions.*.items.*.tax' => ['nullable', 'numeric'],
            'transactions.*.items.*.line_total' => ['nullable', 'numeric'],
            'transactions.*.payments' => ['array'],
            'transactions.*.payments.*.payment_method' => ['nullable', 'string'],
            'transactions.*.payments.*.amount' => ['nullable', 'numeric'],
            'transactions.*.payments.*.reference' => ['nullable', 'string'],
        ]);

        foreach ($payload['transactions'] as $i => $txData) {
            if (($txData['shift_id'] ?? null) === '') {
                $payload['transactions'][$i]['shift_id'] = null;
            }
        }

        foreach ($payload['transactions'] as $i => $txData) {
            if (! is_string($txData['occurred_at'] ?? null)
                && ! is_int($txData['occurred_at'] ?? null)
                && ! is_float($txData['occurred_at'] ?? null)) {
                return response()->json([
                    'message' => 'El campo transactions.'.$i.'.occurred_at debe ser texto o número (timestamp).',
                    'errors' => [
                        'transactions.'.$i.'.occurred_at' => [
                            'El campo debe ser texto o número (timestamp).',
                        ],
                    ],
                ], 422);
            }
        }

        foreach ($payload['transactions'] as $i => $txData) {
            try {
                $raw = $this->normalizeFlexibleTimestampInput($txData['occurred_at']);
                $payload['transactions'][$i]['occurred_at'] = $this->parseFlexibleTimestamp($raw);
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => [
                        'transactions.'.$i.'.occurred_at' => [$e->getMessage()],
                    ],
                ], 422);
            }
        }

        $stored = 0;

        // Un producto desconocido no debe tumbar toda la venta: la línea conserva nombre y SKU.
        // Un producto desconocido no tumba la venta (la línea conserva nombre y SKU). id => costo vigente
        // (null si no tiene), que se congela en la línea.
        $knownProducts = Product::withTrashed()
            ->whereIn('id', collect($payload['transactions'])->pluck('items.*.product_id')->flatten()->filter()->unique()->all())
            ->pluck('cost', 'id');

        DB::transaction(function () use ($payload, $device, &$stored, $knownProducts): void {
            foreach ($payload['transactions'] as $txData) {
                // Reintento desordenado: nunca degradar PAID -> PENDING ni reabrir una VOIDED.
                $existing = Transaction::query()
                    ->where('device_id', $device->id)
                    ->where('external_id', $txData['external_id'])
                    ->first();
                if ($existing && self::STATUS_RANK[$txData['status']] < self::STATUS_RANK[$existing->status]) {
                    continue;
                }

                $transaction = Transaction::updateOrCreate(
                    ['device_id' => $device->id, 'external_id' => $txData['external_id']],
                    [
                        'location_id' => $device->location_id,
                        'shift_id' => $txData['shift_id'] ?? null,
                        'user_id' => $txData['user_id'],
                        'turn_number' => $txData['turn_number'],
                        'status' => $txData['status'],
                        'total' => $txData['total'],
                        'occurred_at' => $txData['occurred_at'],
                        'is_synced' => true,
                        'synced_at' => now(),
                    ]
                );

                if ($transaction->wasRecentlyCreated) {
                    $stored++;
                }

                // Un reenvío recrea las líneas: se conserva el costo ya congelado en vez de releer el actual
                $frozenCosts = $transaction->items()->whereNotNull('product_id')->pluck('unit_cost', 'product_id');

                $transaction->items()->delete();
                foreach ($txData['items'] ?? [] as $item) {
                    $productId = isset($item['product_id']) && $knownProducts->has($item['product_id']) ? $item['product_id'] : null;

                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $productId,
                        'unit_cost' => $productId === null ? null : ($frozenCosts->has($productId) ? $frozenCosts[$productId] : $knownProducts[$productId]),
                        'product_name' => Str::limit($item['product_name'] ?? 'unknown', 120, ''),
                        'product_sku' => isset($item['product_sku']) ? Str::limit($item['product_sku'], 50, '') : null,
                        'qty' => $item['qty'] ?? 0,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'discount' => $item['discount'] ?? 0,
                        'tax' => $item['tax'] ?? 0,
                        'line_total' => $item['line_total'] ?? 0,
                    ]);
                }

                $transaction->payments()->delete();
                foreach ($txData['payments'] ?? [] as $payment) {
                    TransactionPayment::create([
                        'transaction_id' => $transaction->id,
                        'payment_method' => Str::limit($payment['payment_method'] ?? 'OTHER', 64, ''),
                        'amount' => $payment['amount'] ?? 0,
                        'reference' => isset($payment['reference']) ? Str::limit($payment['reference'], 100, '') : null,
                    ]);
                }
            }

            $device->update(['last_sync_at' => now()]);
            $device->location->update(['last_sync_at' => now()]);

            SyncState::updateOrCreate(
                ['location_id' => $device->location_id, 'device_id' => $device->id],
                ['last_push_at' => now(), 'last_success_at' => now(), 'last_error_at' => null, 'last_error_message' => null]
            );
        });

        SyncLog::create([
            'location_id' => $device->location_id,
            'device_id' => $device->id,
            'operation' => 'PUSH',
            'entity' => 'transactions',
            'records_count' => $stored,
            'status' => 'SUCCESS',
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'stored' => $stored,
        ]);
    }

    /**
     * Anota el fallo del push para que el dashboard técnico (sync fallidas) y la pantalla
     * de estados de sync lo reflejen; antes solo se registraban los éxitos.
     */
    private function recordPushFailure(Device $device, Carbon $startedAt, Throwable $e): void
    {
        $message = Str::limit($e instanceof ValidationException ? collect($e->errors())->flatten()->first() ?? $e->getMessage() : $e->getMessage(), 1000);

        try {
            SyncLog::create([
                'location_id' => $device->location_id,
                'device_id' => $device->id,
                'operation' => 'PUSH',
                'entity' => 'transactions',
                'records_count' => 0,
                'status' => 'FAILED',
                'started_at' => $startedAt,
                'finished_at' => now(),
                'error_message' => $message,
            ]);

            SyncState::updateOrCreate(
                ['location_id' => $device->location_id, 'device_id' => $device->id],
                ['last_error_at' => now(), 'last_error_message' => $message]
            );
        } catch (Throwable) {
            // El registro del fallo nunca debe ocultar el error original.
        }
    }

    /**
     * Catálogo y datos maestros para el POS (bajada).
     *
     * Query o cabecera `Device-Fingerprint`: `device_fingerprint` obligatorio (debe coincidir con el dispositivo del token).
     * También se acepta el parámetro `deviceFingerprint` (camelCase).
     * lastSyncTimestamp (opcional): ISO 8601 UTC, o milisegundos Unix, o segundos Unix; solo filas con updatedAt (o deletedAt) posteriores.
     *
     * JSON camelCase alineado a entidades Room: LicenseEntity (incluye `deviceFingerprint`, no UUID de dispositivo),
     * UserEntity, FamilyEntity, SubfamilyEntity, ProductEntity, PaymentMethodEntity (tablas license, users, families,
     * subfamilies, products, payment_methods).
     */
    public function pull(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $this->mergePullDeviceFingerprint($request);

        $validated = $request->validate([
            'device_fingerprint' => ['required', 'string', 'max:255', Rule::in([$device->device_fingerprint])],
            'lastSyncTimestamp' => ['nullable', 'string', 'max:80'],
        ], [
            'device_fingerprint.required' => 'La huella del dispositivo es obligatoria.',
            'device_fingerprint.in' => 'La huella no coincide con el dispositivo autenticado.',
        ]);

        $since = null;
        if (isset($validated['lastSyncTimestamp']) && $validated['lastSyncTimestamp'] !== '') {
            try {
                $since = $this->parseFlexibleTimestamp(trim($validated['lastSyncTimestamp']));
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['lastSyncTimestamp' => [$e->getMessage()]],
                ], 422);
            }
        }

        $syncTimestamp = now()->utc();
        $syncTimestampIso = $syncTimestamp->format('Y-m-d\TH:i:s').'Z';

        $license = $device->activeLicense;
        abort_unless($license, 500, 'Active license missing.');

        $licensePayload = $this->licensePayload($device, $license);

        $users = $this->pullUsersForLocation($device->location_id, $since)->map(fn (User $u) => $this->userPayload($u));

        $families = $this->pullFamilies($since)->map(fn (Family $f) => $this->familyPayload($f));

        $subfamilies = $this->pullSubfamilies($since)->map(fn (Subfamily $s) => $this->subfamilyPayload($s));

        $products = $this->pullProducts($since)->map(fn (Product $p) => $this->productPayload($p));

        $promotions = $this->pullPromotions($since)->map(fn (Promotion $pr) => $this->promotionPayload($pr));

        $paymentMethods = $this->paymentMethodsPayload($syncTimestampIso, $since);

        $recordCount = 1 + $users->count() + $families->count() + $subfamilies->count() + $products->count() + $promotions->count() + count($paymentMethods);

        SyncState::updateOrCreate(
            ['location_id' => $device->location_id, 'device_id' => $device->id],
            ['last_pull_at' => now(), 'last_success_at' => now(), 'last_error_at' => null, 'last_error_message' => null]
        );

        SyncLog::create([
            'location_id' => $device->location_id,
            'device_id' => $device->id,
            'operation' => 'PULL',
            'entity' => 'catalog',
            'records_count' => $recordCount,
            'status' => 'SUCCESS',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $device->update(['last_sync_at' => now()]);
        $device->location->update(['last_sync_at' => now()]);

        return response()->json([
            'syncTimestamp' => $syncTimestampIso,
            'data' => [
                'license' => $licensePayload,
                'users' => $users->values()->all(),
                'families' => $families->values()->all(),
                'subfamilies' => $subfamilies->values()->all(),
                'products' => $products->values()->all(),
                'promotions' => $promotions->values()->all(),
                'paymentMethods' => $paymentMethods,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function licensePayload(Device $device, License $license): array
    {
        $active = $license->status === 'ACTIVE'
            && $license->valid_from <= now()
            && $license->valid_to >= now();

        return [
            'deviceFingerprint' => $device->device_fingerprint,
            'isActive' => $active,
            'plan' => config('sync_catalog.license_plan_default', 'standard'),
            'expiresAt' => $license->valid_to->utc()->format('Y-m-d\TH:i:s').'Z',
            'updatedAt' => $license->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
        ];
    }

    private function pullUsersForLocation(string $locationId, ?Carbon $since)
    {
        $q = User::withTrashed()
            ->where(function ($w) use ($locationId): void {
                $w->whereHas('locations', fn ($rel) => $rel->where('locations.id', $locationId))
                    ->orWhere('users.location_id', $locationId);
            });

        if ($since === null) {
            $q->whereNull('deleted_at');
        } else {
            $q->where(function ($w) use ($since): void {
                $w->where('users.updated_at', '>', $since)
                    ->orWhere(function ($w2) use ($since): void {
                        $w2->whereNotNull('users.deleted_at')
                            ->where('users.deleted_at', '>', $since);
                    });
            });
        }

        return $q->orderBy('users.updated_at')->get();
    }

    /**
     * @return Collection<int, Family>
     */
    private function pullFamilies(?Carbon $since)
    {
        $q = Family::withTrashed();
        if ($since === null) {
            $q->whereNull('deleted_at');
        } else {
            $q->where(function ($w) use ($since): void {
                $w->where('families.updated_at', '>', $since)
                    ->orWhere(function ($w2) use ($since): void {
                        $w2->whereNotNull('families.deleted_at')
                            ->where('families.deleted_at', '>', $since);
                    });
            });
        }

        return $q->orderBy('updated_at')->get();
    }

    /**
     * @return Collection<int, Subfamily>
     */
    private function pullSubfamilies(?Carbon $since)
    {
        $q = Subfamily::withTrashed()->with('family');
        if ($since === null) {
            $q->whereNull('deleted_at');
        } else {
            $q->where(function ($w) use ($since): void {
                $w->where('subfamilies.updated_at', '>', $since)
                    ->orWhere(function ($w2) use ($since): void {
                        $w2->whereNotNull('subfamilies.deleted_at')
                            ->where('subfamilies.deleted_at', '>', $since);
                    });
            });
        }

        return $q->orderBy('updated_at')->get();
    }

    /**
     * @return Collection<int, Product>
     */
    private function pullProducts(?Carbon $since)
    {
        $q = Product::withTrashed()->with(['subfamily.family']);
        if ($since === null) {
            $q->whereNull('deleted_at');
        } else {
            $q->where(function ($w) use ($since): void {
                $w->where('products.updated_at', '>', $since)
                    ->orWhere(function ($w2) use ($since): void {
                        $w2->whereNotNull('products.deleted_at')
                            ->where('products.deleted_at', '>', $since);
                    });
            });
        }

        return $q->orderBy('updated_at')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $u): array
    {
        return [
            'id' => $u->id,
            'username' => $u->username,
            // pin_sha384: SHA-384 hex del texto en claro (trim), para validación offline; fallback legacy si aún no existe.
            'pin' => $u->pin_sha384 ?? hash('sha384', $u->getAuthPassword()),
            'pin4' => $u->pin4_sha384, // SHA-384 del PIN rápido de 4 dígitos (null si no está definido)
            'role' => strtolower((string) $u->role),
            'isActive' => (bool) $u->is_active,
            'updatedAt' => $u->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
            'deletedAt' => $u->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function familyPayload(Family $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'description' => $f->description !== null && $f->description !== '' ? $f->description : null,
            'imageUrl' => ImageThumbnailService::syncUrl($f->image_url),
            'updatedAt' => $f->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
            'deletedAt' => $f->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subfamilyPayload(Subfamily $s): array
    {
        return [
            'id' => $s->id,
            'familyId' => $s->family_id,
            'name' => $s->name,
            'updatedAt' => $s->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
            'deletedAt' => $s->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return Collection<int, Promotion>
     */
    private function pullPromotions(?Carbon $since): Collection
    {
        $q = Promotion::withTrashed();
        if ($since === null) {
            $q->whereNull('deleted_at');
        } else {
            $q->where(function ($w) use ($since): void {
                $w->where('promotions.updated_at', '>', $since)
                    ->orWhere(function ($w2) use ($since): void {
                        $w2->whereNotNull('promotions.deleted_at')
                            ->where('promotions.deleted_at', '>', $since);
                    });
            });
        }

        return $q->orderBy('promotions.updated_at')->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function promotionPayload(Promotion $pr): array
    {
        $scope = $pr->scopeAttribute();
        $scopeType = $scope['scopeType'];
        $scopeId = $scope['scopeId'];

        return [
            'id' => $pr->id,
            'name' => $pr->name,
            'description' => $pr->effectiveDescription(),
            'type' => $pr->type,
            'value' => (float) $pr->value,
            'buyQty' => $pr->buy_qty !== null ? (int) $pr->buy_qty : null,
            'payQty' => $pr->pay_qty !== null ? (int) $pr->pay_qty : null,
            'scopeType' => $scopeType,
            'scopeId' => $scopeId,
            'scopeName' => $pr->displayScopeName(),
            'startsAt' => $pr->starts_at?->utc()->format('Y-m-d\TH:i:s').'Z',
            'endsAt' => $pr->ends_at?->utc()->format('Y-m-d\TH:i:s').'Z',
            'isActive' => (bool) $pr->is_active,
            'updatedAt' => $pr->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
            'deletedAt' => $pr->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(Product $p): array
    {
        $sub = $p->subfamily;
        $fam = $sub?->family;
        $taxRateFraction = round(((float) $p->tax_rate) / 100, 4);

        $sku = $p->sku !== null && $p->sku !== '' ? $p->sku : null;
        $desc = $p->description !== null && $p->description !== '' ? $p->description : null;
        $barcodeVal = $p->barcode !== null && $p->barcode !== '' ? $p->barcode : null;
        $familyName = $fam?->name ?? '';
        $isFavorite = (bool) $p->is_favorite;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $desc,
            'sku' => $sku,
            'codebar' => $barcodeVal,
            'imageUrl' => ImageThumbnailService::syncUrl($p->image_url),
            'familyName' => $familyName,
            'categoria' => $familyName,
            'subfamilyName' => $sub?->name ?? '',
            'family_id' => $sub?->family_id,
            'subfamily_id' => $p->subfamily_id,
            'unitPrice' => (float) $p->price,
            'taxName' => $p->tax_rate > 0 ? 'IVA '.rtrim(rtrim(number_format((float) $p->tax_rate, 2, '.', ''), '0'), '.').'%' : 'Sin impuesto',
            'taxRate' => $taxRateFraction,
            'isFavorite' => $isFavorite,
            'updatedAt' => $p->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
            'deletedAt' => $p->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Métodos de pago desde BD (gestionables en el panel). El POS renderiza
     * un botón por método usando name/type/color.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentMethodsPayload(string $syncTimestampIso, ?Carbon $since): array
    {
        $q = PaymentMethod::withTrashed();
        if ($since === null) {
            $q->whereNull('deleted_at');
        } else {
            $q->where(function ($w) use ($since): void {
                $w->where('payment_methods.updated_at', '>', $since)
                    ->orWhere(function ($w2) use ($since): void {
                        $w2->whereNotNull('payment_methods.deleted_at')
                            ->where('payment_methods.deleted_at', '>', $since);
                    });
            });
        }

        return $q->orderBy('name')->get()->map(fn (PaymentMethod $pm) => [
            'id' => $pm->id,
            'name' => $pm->name,
            'type' => $pm->type,
            'color' => $pm->color,
            'isEnabled' => (bool) $pm->is_enabled,
            'updatedAt' => $pm->updated_at->utc()->format('Y-m-d\TH:i:s').'Z',
            'deletedAt' => $pm->deleted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ])->all();
    }

    /**
     * Convierte entrada de cliente (string / int / float) a string para {@see parseFlexibleTimestamp()}.
     */
    private function normalizeFlexibleTimestampInput(string|int|float $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (floor($value) == $value) {
            return sprintf('%.0f', $value);
        }

        return (string) $value;
    }

    /**
     * Acepta ISO 8601, milisegundos Unix (≥13 dígitos o valor ≥ 1e12), o segundos Unix.
     * Devuelve el instante en la zona de la app (GMT-4): la BD almacena hora de muro
     * local, y las comparaciones de delta/filtros son contra esa hora.
     * Usado en pull (`lastSyncTimestamp`) y push (`occurred_at`).
     */
    private function parseFlexibleTimestamp(string $raw): Carbon
    {
        $appTz = config('app.timezone');
        $s = trim($raw);
        if ($s === '') {
            throw new InvalidArgumentException('El valor no puede estar vacío.');
        }

        if (preg_match('/^\d+$/', $s)) {
            $n = (int) $s;

            if (strlen($s) >= 13 || $n >= 1_000_000_000_000) {
                return Carbon::createFromTimestampMs($n, $appTz);
            }

            return Carbon::createFromTimestamp($n, $appTz);
        }

        try {
            return Carbon::parse($s)->timezone($appTz);
        } catch (Throwable) {
            throw new InvalidArgumentException('No es una fecha ISO válida ni un timestamp numérico (segundos o milisegundos).');
        }
    }

    /**
     * Cabecera HTTP `Device-Fingerprint`, o query `device_fingerprint` / `deviceFingerprint`.
     */
    private function mergePullDeviceFingerprint(Request $request): void
    {
        $h = $request->header('Device-Fingerprint');
        if (is_string($h) && $h !== '') {
            $request->merge(['device_fingerprint' => $h]);

            return;
        }

        foreach (['device_fingerprint', 'deviceFingerprint'] as $key) {
            $v = $request->input($key);
            if (is_string($v) && $v !== '') {
                $request->merge(['device_fingerprint' => $v]);

                return;
            }
        }
    }
}
