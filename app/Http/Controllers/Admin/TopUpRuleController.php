<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\TopUpRule;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Alta y edición de reglas de recarga: una regla suma las condiciones que se activen (todas deben cumplirse). */
class TopUpRuleController extends Controller
{
    public function create(): View
    {
        $this->authorizeEdit();

        return view('admin.topup-rules.form', ['rule' => null, 'locations' => $this->locations()]);
    }

    public function edit(string $id): View
    {
        $this->authorizeEdit();

        return view('admin.topup-rules.form', ['rule' => TopUpRule::query()->findOrFail($id), 'locations' => $this->locations()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeEdit();
        $rule = TopUpRule::create($this->validated($request));

        return redirect()->route('admin.screens.index', 'topup-rules')->with('status', "Regla «{$rule->name}» creada.");
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->authorizeEdit();
        $rule = TopUpRule::query()->findOrFail($id);
        $rule->update($this->validated($request));

        return redirect()->route('admin.screens.index', 'topup-rules')->with('status', "Regla «{$rule->name}» actualizada.");
    }

    /** @return array<string, mixed> */
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
        ], [
            'time_from.required_with' => 'Indica también la hora de fin.',
            'time_to.required_with' => 'Indica también la hora de inicio.',
        ], [
            'name' => 'nombre', 'location_ids' => 'localidades', 'weekdays' => 'días', 'time_from' => 'hora de inicio', 'time_to' => 'hora de fin',
            'max_per_topup' => 'tope por recarga', 'max_daily_amount' => 'tope por día',
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

        return [
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
        ];
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
