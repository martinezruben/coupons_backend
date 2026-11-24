<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Location;
use App\Models\Shift;
use App\Services\CashClose;
use App\Services\Shifts;
use App\Support\AdminLocationScope;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turnos por localidad: el turno vigente de cada una con lo que lleva, el cierre a una hora de corte (lo anterior queda
 * en el turno, lo posterior pasa al siguiente) y el historial de turnos cerrados. Actúa sobre la localidad elegida en el
 * selector de la barra superior y solo sobre las que el permiso del administrador alcanza.
 */
class ShiftController extends Controller
{
    use StreamsCsv;

    public function index(Request $request): View
    {
        $admin = $this->admin($request, 'view');
        $ids = AdminLocationScope::effective($admin);
        $locations = Location::query()->visibleToLocations($admin)->orderBy('name')->get(['id', 'name']);

        $cards = $locations->map(function (Location $l) use ($admin) {
            $shift = Shifts::current($l);
            $s = CashClose::summarize($l->id, $shift->starts_at, now()->addMinute(), $admin);

            return ['location' => $l, 'shift' => $shift, 'totals' => $s['totals']];
        });

        return view('admin.shifts.index', [
            'cards' => $cards,
            'history' => Shift::query()->visibleToLocations($admin)->with(['location', 'closedBy'])->where('status', Shift::CLOSED)->orderByDesc('closed_at')->orderByDesc('id')->paginate(15)->withQueryString(),
            'narrowed' => $ids !== null,
        ]);
    }

    public function location(Request $request, string $location): View
    {
        $admin = $this->admin($request, 'view');
        $loc = Location::query()->visibleToLocations($admin)->findOrFail($location);
        $shift = Shifts::current($loc);

        $request->validate(['until' => ['nullable', 'date']]);
        $until = $request->filled('until') ? Carbon::parse($request->query('until'))->startOfMinute() : now()->startOfMinute();
        $valid = $until->gt($shift->starts_at) && ! $until->isFuture();

        return view('admin.shifts.location', [
            'location' => $loc, 'shift' => $shift, 'until' => $until, 'valid' => $valid,
            'preview' => $valid ? Shifts::preview($shift, $until, $admin) : null,
            'canClose' => $this->canClose($admin),
            'history' => Shift::query()->where('location_id', $loc->id)->where('status', Shift::CLOSED)->orderByDesc('number')->limit(10)->get(),
        ]);
    }

    public function close(Request $request, string $location): RedirectResponse
    {
        $admin = $this->admin($request, 'close');
        abort_unless($this->canClose($admin), 403);
        $loc = Location::query()->visibleToLocations($admin)->findOrFail($location);
        $data = $request->validate(['until' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:255']]);

        try {
            $shift = Shifts::close($loc, Carbon::parse($data['until']), $data['note'] ?? null, $admin);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['close' => $e->getMessage()]);
        }

        AdminAuditLog::record('shift_close', 'Location', (string) $loc->id, [
            'turno' => $shift->number, 'periodo' => [$shift->starts_at->toDateTimeString(), $shift->ends_at->toDateTimeString()],
            'cobrado' => (string) $shift->redeemed, 'reversado' => (string) $shift->reversed, 'neto' => (string) $shift->net,
        ], $admin);

        return redirect()->route('admin.shifts.show', $shift)->with('status', "Turno {$shift->number} cerrado. Lo de {$shift->ends_at->format('H:i')} en adelante queda en el turno ".($shift->number + 1).'.');
    }

    public function show(Request $request, int $shift): View|StreamedResponse
    {
        $admin = $this->admin($request, 'view');
        $s = Shift::query()->visibleToLocations($admin)->with(['location', 'closedBy'])->findOrFail($shift);
        if ($s->isOpen()) {
            return redirect()->route('admin.shifts.location', $s->location_id);
        }
        $summary = [
            'totals' => ['redemptions' => $s->redemptions, 'redeemed' => (float) $s->redeemed, 'reversed' => (float) $s->reversed, 'net' => (float) $s->net, 'topups' => (float) $s->topups],
            'cashiers' => $s->details['cashiers'] ?? [], 'dispensers' => $s->details['dispensers'] ?? [],
        ];

        if ($request->query('export') === 'csv') {
            abort_unless($admin->can(AdminRbac::actionPermissions('coupon-cash-close')['export']['permission']), 403);

            return $this->csv("turno-{$s->number}-{$s->location?->name}.csv", $s->location?->name ?? '—', $s, $summary);
        }

        return view('admin.shifts.show', [
            'shift' => $s, 'summary' => $summary,
            'canExport' => $admin->can(AdminRbac::actionPermissions('coupon-cash-close')['export']['permission']),
        ]);
    }

    private function canClose(AdminUser $admin): bool
    {
        // Un turno con el alcance recortado a ciertos clientes saldría incompleto
        return $admin->can(AdminRbac::actionPermissions('coupon-cash-close')['close']['permission']) && $admin->allowedCustomerIds() === null;
    }

    private function admin(Request $request, string $action): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = $request->user('admin');
        $perm = $action === 'view' ? AdminRbac::permissionsForScreen('coupon-cash-close')['view'] : AdminRbac::actionPermissions('coupon-cash-close')[$action]['permission'];
        abort_unless($admin && $admin->can($perm), 403);

        return $admin;
    }

    private function csv(string $name, string $location, Shift $s, array $summary): StreamedResponse
    {
        $m = fn ($v) => number_format((float) $v, 2, '.', '');
        $rows = [['Localidad: '.$location, 'Turno '.$s->number, $s->starts_at->format('d/m/Y H:i').' a '.$s->ends_at->format('d/m/Y H:i'), '', '', '', '', '']];
        foreach ($summary['cashiers'] as $c) {
            $rows[] = [$c['name'], $c['redemptions'], $m($c['redeemed']), $m($c['reversed']), $m($c['net']), $m($c['topups']), $c['first_at'], $c['last_at']];
        }
        $t = $summary['totals'];
        $rows[] = ['TOTAL', $t['redemptions'], $m($t['redeemed']), $m($t['reversed']), $m($t['net']), $m($t['topups']), '', ''];
        $rows[] = ['', '', '', '', '', '', '', ''];
        $rows[] = ['SURTIDOR', 'Cobros (n.º)', 'Cobrado', 'Reversado', 'Neto', '', '', ''];
        foreach ($summary['dispensers'] as $d) {
            $rows[] = [$d['dispenser'] === null ? 'Sin surtidor' : 'Surtidor '.$d['dispenser'], $d['redemptions'], $m($d['redeemed']), $m($d['reversed']), $m($d['net']), '', '', ''];
        }

        return $this->streamCsv($name, ['Cajero', 'Cobros (n.º)', 'Cobrado', 'Reversado', 'Neto cobrado', 'Recargado', 'Primer movimiento', 'Último movimiento'], $rows);
    }
}
