<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CouponException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateCouponBatch;
use App\Models\ActivationOrder;
use App\Models\CouponBatch;
use App\Models\CouponPrefix;
use App\Models\Customer;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lotes de cupones: generar, descargar el archivo con los tokens (única vez), asignar y activar
 * una selección para un cliente (ahora o en una fecha), cancelar activaciones programadas,
 * liberar y anular. Cada acción tiene su permiso (`coupon_batches.*`) y deja auditoría.
 */
class CouponBatchController extends Controller
{
    private const SCREEN = 'coupon-batches';

    public function create(): View
    {
        $this->authorizeAction('generate');

        return view('admin.coupon-batches.create', [
            'max' => CouponBatchGenerator::MAX_QUANTITY,
            'maxAmount' => (int) config('coupons.max_amount'),
            'prefixes' => CouponPrefix::query()->where('is_active', true)->orderBy('prefix')->get(['prefix', 'description']),
            // Vencimiento por defecto: un año contado desde mañana
            'defaultExpire' => now()->addDay()->addYear()->toDateString(),
            'tomorrow' => now()->addDay()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAction('generate');

        if (is_string($request->input('prefix'))) {
            $request->merge(['prefix' => strtoupper(trim($request->input('prefix')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'coupon_type' => ['required', Rule::in(['QRCODE', 'CODBAR'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CouponBatchGenerator::MAX_QUANTITY],
            'amount' => ['required', 'integer', 'min:1', 'max:'.(int) config('coupons.max_amount')],
            'expire_date' => ['nullable', 'date', 'after:today'],
            'prefix' => ['nullable', Rule::exists('coupon_prefixes', 'prefix')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['amount.integer' => 'El monto debe ser un número entero, sin decimales.', 'prefix.exists' => 'Elige un prefijo del catálogo (o déjalo vacío).'],
            ['name' => 'nombre', 'quantity' => 'cantidad', 'amount' => 'monto', 'expire_date' => 'fecha de vencimiento', 'prefix' => 'prefijo', 'notes' => 'notas']);

        try {
            $batch = CouponBatchGenerator::create($data, $request->user('admin'), $request->ip());
        } catch (CouponException $e) {
            return back()->withInput()->withErrors(['coupon' => $this->explain($e)]);
        }
        GenerateCouponBatch::dispatch($batch->getKey())->afterCommit();

        return redirect()->route('admin.coupon-batches.show', $batch)
            ->with('status', 'Lote creado. La generación corre en segundo plano; el archivo con los tokens estará listo al terminar.');
    }

    public function show(CouponBatch $batch): View
    {
        $this->authorizeView();

        $admin = auth('admin')->user();
        $table = (new ($batch->couponModel()))->getTable();
        $allowed = $admin->allowedCustomerIds();
        $funnel = DB::table($table)->where('batch_id', $batch->getKey())
            // Con alcance por cliente: los generados sin asignar (disponibilidad) y solo los de sus clientes
            ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->where('status', 'GENERATED')->orWhereIn('customer_id', $allowed)))
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('admin.coupon-batches.show', [
            'batch' => $batch->load('creator'),
            'funnel' => $funnel,
            'restricted' => $allowed !== null,
            'orders' => $batch->orders()->visibleTo($admin)->with(['customer', 'requester'])->latest()->limit(10)->get(),
            'can' => collect(array_keys(AdminRbac::actionPermissions(self::SCREEN)))
                ->mapWithKeys(fn ($a) => [$a => $this->can($a)])->all() + ['operate' => $this->canAny(['assign', 'activate', 'schedule'])],
        ]);
    }

    /** Entrega el CSV con los tokens y lo borra: es la única copia en claro. */
    public function download(Request $request, CouponBatch $batch): StreamedResponse|RedirectResponse
    {
        $this->authorizeAction('download');

        if (! $batch->hasExportFile() || ! Storage::disk('local')->exists($batch->export_path)) {
            return back()->withErrors(['coupon' => 'El archivo de este lote ya no está disponible (se descarga una sola vez o vence a las '.config('coupons.export_ttl_hours').' h). Si lo perdiste, anula el lote y genera uno nuevo.']);
        }

        $path = Storage::disk('local')->path($batch->export_path);
        $batch->forceFill(['export_downloaded_at' => now(), 'export_downloads' => $batch->export_downloads + 1])->save();
        CouponBatchGenerator::audit($batch, 'batch_download', $request->user('admin'), $request->ip(), '', '', "Descarga del archivo del lote {$batch->code}");

        return response()->streamDownload(function () use ($path, $batch) {
            readfile($path);
            // Solo si se envió completo: ya no queda ninguna copia en claro.
            Storage::disk('local')->delete($batch->export_path);
            $batch->forceFill(['export_path' => null])->save();
        }, "{$batch->code}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function activateForm(CouponBatch $batch): View
    {
        abort_unless($this->canAny(['assign', 'activate', 'schedule']), 403);

        return view('admin.coupon-batches.activate', $this->activateData($batch));
    }

    /** `step=preview` muestra cuántos cupones se afectarían; `step=confirm` aplica. */
    public function activate(Request $request, CouponBatch $batch, CouponActivationService $service): View|RedirectResponse
    {
        [$type, $input] = $this->selection($request);
        $data = $request->validate([
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'mode' => ['required', Rule::in(['assign', 'now', 'scheduled'])],
            'activate_at' => ['nullable', 'required_if:mode,scheduled', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['customer_id' => 'cliente', 'mode' => 'modo', 'activate_at' => 'fecha de activación', 'reason' => 'motivo']);

        // Cada modo y alcance tiene su permiso: asignar, activar una selección, programar, operar el lote completo
        $this->authorizeAction(['assign' => 'assign', 'now' => 'activate', 'scheduled' => 'schedule'][$data['mode']]);
        if ($type === 'all') {
            $this->authorizeAction('activate_all');
        }

        $customer = Customer::findOrFail($data['customer_id']);
        $at = ($data['mode'] === 'scheduled') ? Carbon::parse($data['activate_at']) : null;

        try {
            if ($request->input('step') !== 'confirm') {
                $summary = $service->preview($batch, $data['mode'], $type, $input, $customer, $request->user('admin'));

                return view('admin.coupon-batches.activate-confirm', [
                    'batch' => $batch, 'customer' => $customer, 'summary' => $summary, 'mode' => $data['mode'], 'at' => $at,
                    'payload' => array_merge($request->except(['_token', 'step', 'numbers_file']), $type === 'list' ? ['numbers' => implode("\n", $input['numbers'])] : []),
                ]);
            }

            $order = $service->submit($batch, $customer, $data['mode'], $at, $type, $input, $data['reason'] ?? null, $request->user('admin'), $request->ip());
        } catch (CouponException $e) {
            return back()->withInput($request->except('numbers_file'))->withErrors(['coupon' => $this->explain($e)]);
        }

        $done = ['assign' => 'asignados', 'now' => 'activados', 'scheduled' => 'programados para activarse'][$data['mode']];

        return redirect()->route('admin.coupon-batches.show', $batch)->with('status', "{$order->coupons_count} cupones {$done} para {$customer->name}.");
    }

    public function unassign(Request $request, CouponBatch $batch, CouponActivationService $service): RedirectResponse
    {
        $this->authorizeAction('assign');
        [$type, $input] = $this->selection($request);
        if ($type === 'all') {
            $this->authorizeAction('activate_all');
        }
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $count = $service->unassign($batch, $type, $input, $reason, $request->user('admin'), $request->ip());
        } catch (CouponException $e) {
            return back()->withInput($request->except('numbers_file'))->withErrors(['coupon' => $this->explain($e)]);
        }

        return redirect()->route('admin.coupon-batches.show', $batch)->with('status', "{$count} cupones liberados.");
    }

    public function cancelOrder(Request $request, ActivationOrder $order, CouponActivationService $service): RedirectResponse
    {
        $this->authorizeAction('cancel');
        abort_unless(ActivationOrder::query()->visibleTo($request->user('admin'))->whereKey($order->getKey())->exists(), 404);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        try {
            $service->cancel($order, $reason, $request->user('admin'), $request->ip());
        } catch (CouponException $e) {
            return back()->withErrors(['coupon' => $this->explain($e)]);
        }

        return back()->with('status', 'Activación programada cancelada. Los cupones quedan asignados al cliente, sin activarse.');
    }

    public function void(Request $request, CouponBatch $batch, CouponActivationService $service): RedirectResponse
    {
        $this->authorizeAction('void');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [], ['reason' => 'motivo']);

        try {
            $count = $service->voidBatch($batch, $data['reason'], $request->user('admin'), $request->ip());
        } catch (CouponException $e) {
            return back()->withErrors(['coupon' => $this->explain($e)]);
        }

        return redirect()->route('admin.coupon-batches.show', $batch)->with('status', "Lote anulado: {$count} cupones sin activar quedaron anulados (los ya activos no se tocan).");
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function selection(Request $request): array
    {
        $type = $request->validate(['selection_type' => ['required', Rule::in(CouponActivationService::SELECTIONS)]], [], ['selection_type' => 'selección'])['selection_type'];
        $input = match ($type) {
            'range' => $request->validate(['from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'gte:from']], [], ['from' => 'desde', 'to' => 'hasta']),
            'quantity' => $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.config('coupons.max_activation_size')]], [], ['quantity' => 'cantidad']),
            'list' => ['numbers' => $this->numbersFrom($request)],
            default => [],
        };

        return [$type, $input];
    }

    /** @return list<string> */
    private function numbersFrom(Request $request): array
    {
        $request->validate(['numbers' => ['nullable', 'string', 'max:600000'], 'numbers_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:2048']], [], ['numbers' => 'lista', 'numbers_file' => 'archivo']);
        $raw = (string) $request->input('numbers', '');
        if ($request->hasFile('numbers_file')) {
            $raw .= "\n".file_get_contents($request->file('numbers_file')->getRealPath());
        }
        $numbers = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', $raw) ?: [])));
        $numbers = array_values(array_filter($numbers, fn ($n) => strcasecmp($n, 'numero') !== 0 && strcasecmp($n, 'número') !== 0));

        if ($numbers === []) {
            throw ValidationException::withMessages(['numbers' => 'Pega o sube al menos un número de cupón.']);
        }
        if (count($numbers) > (int) config('coupons.max_activation_size')) {
            throw ValidationException::withMessages(['numbers' => 'La lista supera el máximo por operación ('.config('coupons.max_activation_size').').']);
        }

        return $numbers;
    }

    private function activateData(CouponBatch $batch): array
    {
        return [
            'batch' => $batch,
            'customers' => Customer::query()->visibleTo(auth('admin')->user())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'document']),
            'modes' => collect(['now' => 'activate', 'scheduled' => 'schedule', 'assign' => 'assign'])->filter(fn ($perm) => $this->can($perm))->keys()->all(),
            'canAll' => $this->can('activate_all'),
        ];
    }

    private function explain(CouponException $e): string
    {
        $extra = collect($e->extra())->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · ');
        $messages = [
            'The person who generated a batch cannot assign or activate it' => 'Quien generó el lote no puede asignarlo ni activarlo: pide a otra persona con permiso que lo haga.',
            'There are no eligible coupons for this selection' => 'No hay cupones elegibles con esa selección (solo se asignan los generados y solo se activan los generados o asignados al mismo cliente).',
            'The batch is not ready' => 'El lote no está listo (sigue generándose, falló o está anulado).',
            'The customer is not active' => 'El cliente está inactivo.',
            'The activation date must be at least one minute from now and within a year' => 'La fecha de activación debe ser al menos un minuto en el futuro y dentro del próximo año.',
            'You can only work with the customers assigned to you' => 'Solo puedes trabajar con los clientes que te asignaron.',
            'Only a pending scheduled activation can be cancelled' => 'Solo se puede cancelar una activación programada que siga pendiente.',
        ];

        return ($messages[$e->getMessage()] ?? $e->getMessage()).($extra !== '' ? " ({$extra})" : '');
    }

    private function can(string $action): bool
    {
        $permission = AdminRbac::actionPermissions(self::SCREEN)[$action]['permission'] ?? null;
        $user = auth('admin')->user();

        return (bool) ($permission && $user && $user->can($permission));
    }

    /** @param list<string> $actions */
    private function canAny(array $actions): bool
    {
        return collect($actions)->contains(fn ($a) => $this->can($a));
    }

    private function authorizeAction(string $action): void
    {
        abort_unless($this->can($action), 403);
    }

    private function authorizeView(): void
    {
        $user = auth('admin')->user();
        abort_unless($user && $user->can(AdminRbac::permissionsForScreen(self::SCREEN)['view']), 403);
    }
}
