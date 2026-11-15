<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponBatch;
use App\Models\Location;
use App\Models\TopUpRule;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Alta y edición de reglas de recarga: una regla suma las condiciones que se activen (todas deben cumplirse). */
class TopUpRuleController extends Controller
{
    public function create(): View
    {
        $this->authorizeEdit();

        return view('admin.topup-rules.form', ['rule' => null, 'locations' => $this->locations(), 'batches' => $this->batches(), 'numbers' => '', 'covered' => null]);
    }

    public function edit(string $id): View
    {
        $this->authorizeEdit();

        $rule = TopUpRule::query()->findOrFail($id);

        return view('admin.topup-rules.form', ['rule' => $rule, 'locations' => $this->locations(), 'batches' => $this->batches(), 'numbers' => implode("\n", $rule->assignedNumbers()), 'covered' => $this->coveredCount($rule)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeEdit();
        [$data, $numbers] = $this->validated($request);
        $rule = DB::transaction(function () use ($data, $numbers) {
            $rule = TopUpRule::create($data);
            $this->syncNumbers($rule, $numbers);

            return $rule;
        });

        return redirect()->route('admin.screens.index', 'topup-rules')->with('status', "Regla «{$rule->name}» creada.");
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->authorizeEdit();
        $rule = TopUpRule::query()->findOrFail($id);
        [$data, $numbers] = $this->validated($request);
        DB::transaction(function () use ($rule, $data, $numbers) {
            $rule->update($data);
            $this->syncNumbers($rule, $numbers);
        });

        return redirect()->route('admin.screens.index', 'topup-rules')->with('status', "Regla «{$rule->name}» actualizada.");
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'coupon_type' => ['nullable', Rule::in(['QRCODE', 'CODBAR'])],
            'is_active' => ['nullable', 'boolean'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['uuid', Rule::exists('locations', 'id')],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'time_from' => ['nullable', 'date_format:H:i', 'required_with:time_to'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from'],
            'max_per_topup' => ['nullable', 'numeric', 'gt:0', 'max:'.config('coupons.max_amount'), 'decimal:0,2'],
            'max_daily_amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'assign_all' => ['nullable', 'boolean'],
            'batch_ids' => ['nullable', 'array'],
            'batch_ids.*' => ['uuid', Rule::exists('coupon_batches', 'id')],
            'date_field' => ['nullable', Rule::in(['issued', 'expire'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'numbers' => ['nullable', 'string', 'max:200000'],
        ], [
            'time_from.required_with' => 'Indica también la hora de fin.',
            'time_to.required_with' => 'Indica también la hora de inicio.',
        ], [
            'name' => 'nombre', 'location_ids' => 'localidades', 'weekdays' => 'días', 'time_from' => 'hora de inicio', 'time_to' => 'hora de fin',
            'max_per_topup' => 'tope por recarga', 'max_daily_amount' => 'tope por día',
            'batch_ids' => 'lotes', 'date_from' => 'fecha inicial', 'date_to' => 'fecha final', 'numbers' => 'números de cupón',
        ]);

        $days = collect($data['weekdays'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values();
        $locations = array_values(array_unique($data['location_ids'] ?? []));

        $hasCondition = $locations !== [] || $days->isNotEmpty() || ! empty($data['time_from']) || ! empty($data['max_per_topup']) || ! empty($data['max_daily_amount']);
        if (! $hasCondition) {
            throw ValidationException::withMessages(['conditions' => 'Agrega al menos una condición: una regla sin condiciones no limita nada.']);
        }
        if (! empty($data['max_per_topup']) && ! empty($data['max_daily_amount']) && (float) $data['max_per_topup'] > (float) $data['max_daily_amount']) {
            throw ValidationException::withMessages(['max_per_topup' => 'El tope por recarga no puede superar el tope por día.']);
        }

        // A qué cupones se asigna: todos, lotes, un rango de fechas o una lista de números
        $numbers = collect(preg_split('/[\s,;]+/', (string) ($data['numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($n) => trim($n))->unique()->values();
        if ($numbers->count() > 5000) {
            throw ValidationException::withMessages(['numbers' => 'Máximo 5,000 números por regla; para más, usa un lote o un rango de fechas.']);
        }
        if ($bad = $numbers->first(fn ($n) => ! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $n))) {
            throw ValidationException::withMessages(['numbers' => "El número «{$bad}» no es válido (solo letras, números, guion y guion bajo)."]);
        }
        $batchIds = array_values(array_unique($data['batch_ids'] ?? []));
        $hasDates = ! empty($data['date_from']) || ! empty($data['date_to']);
        if ($hasDates && empty($data['date_field'])) {
            throw ValidationException::withMessages(['date_field' => 'Elige si las fechas son de emisión o de vencimiento.']);
        }
        if (! $request->boolean('assign_all') && $batchIds === [] && ! $hasDates && $numbers->isEmpty()) {
            throw ValidationException::withMessages(['assignment' => 'Asigna la regla a algún cupón: todos, por lote, por fechas o por número. Una regla sin cupones no se aplica a nada.']);
        }
        if ($numbers->isNotEmpty()) {
            $tables = match ($data['coupon_type'] ?? null) {
                'QRCODE' => ['coupons'], 'CODBAR' => ['coupons_codbar'], default => ['coupons', 'coupons_codbar'],
            };
            $found = collect($tables)->flatMap(fn ($t) => DB::table($t)->whereIn('coupon_number', $numbers)->pluck('coupon_number'))->all();
            $unknown = $numbers->diff($found)->values();
            if ($unknown->isNotEmpty()) {
                throw ValidationException::withMessages(['numbers' => $unknown->count().' número(s) no existen: '.$unknown->take(8)->implode(', ').($unknown->count() > 8 ? '…' : '')]);
            }
        }

        return [[
            'name' => $data['name'],
            'type' => TopUpRule::MODERN,
            'coupon_type' => $data['coupon_type'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'location_id' => null,
            'location_ids' => $locations ?: null,
            'weekdays' => $days->isNotEmpty() ? $days->implode(',') : null,
            'time_from' => ! empty($data['time_from']) ? $data['time_from'] : null,
            'time_to' => ! empty($data['time_to']) ? $data['time_to'] : null,
            'max_per_topup' => ! empty($data['max_per_topup']) ? $data['max_per_topup'] : null,
            'max_daily_amount' => ! empty($data['max_daily_amount']) ? $data['max_daily_amount'] : null,
            'assign_all' => $request->boolean('assign_all'),
            'batch_ids' => $batchIds ?: null,
            'date_field' => $hasDates ? $data['date_field'] : null,
            'date_from' => ! empty($data['date_from']) ? $data['date_from'] : null,
            'date_to' => ! empty($data['date_to']) ? $data['date_to'] : null,
        ], $numbers->all()];
    }

    /** @param  list<string>  $numbers */
    private function syncNumbers(TopUpRule $rule, array $numbers): void
    {
        DB::table('topup_rule_coupons')->where('rule_id', $rule->getKey())->delete();
        foreach (array_chunk($numbers, 500) as $chunk) {
            DB::table('topup_rule_coupons')->insert(array_map(fn ($n) => ['rule_id' => $rule->getKey(), 'coupon_number' => $n], $chunk));
        }
    }

    /** Cuántos cupones cubre hoy la regla (para que quien la edita vea el alcance real). */
    private function coveredCount(TopUpRule $rule): int
    {
        $tables = match ($rule->coupon_type) {
            'QRCODE' => ['coupons'], 'CODBAR' => ['coupons_codbar'], default => ['coupons', 'coupons_codbar'],
        };
        $numbers = $rule->assignedNumbers();
        $total = 0;
        foreach ($tables as $table) {
            $q = DB::table($table);
            if (! ($rule->isLegacy() || $rule->assign_all)) {
                $q->where(function ($w) use ($rule, $numbers) {
                    if (! empty($rule->batch_ids)) {
                        $w->orWhereIn('batch_id', $rule->batch_ids);
                    }
                    if ($rule->date_from !== null || $rule->date_to !== null) {
                        $col = $rule->date_field === 'expire' ? 'coupon_expire_date' : 'coupon_date';
                        $w->orWhere(function ($d) use ($rule, $col) {
                            $rule->date_from && $d->whereDate($col, '>=', $rule->date_from->toDateString());
                            $rule->date_to && $d->whereDate($col, '<=', $rule->date_to->toDateString());
                        });
                    }
                    if ($numbers !== []) {
                        $w->orWhereIn('coupon_number', $numbers);
                    }
                });
            }
            $total += $q->count();
        }

        return $total;
    }

    private function batches()
    {
        return CouponBatch::query()->orderByDesc('created_at')->limit(300)->get(['id', 'code', 'name', 'coupon_type']);
    }

    private function locations()
    {
        return Location::query()->orderBy('name')->get(['id', 'name']);
    }

    private function authorizeEdit(): void
    {
        $admin = auth('admin')->user();
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('topup-rules')['edit']), 403);
    }
}
