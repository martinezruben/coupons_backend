<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CashClosing;
use App\Models\Location;
use App\Services\CashClose;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Cierre de caja por localidad: vista previa del turno, cierre (foto inmutable) e historial de cierres. */
class CashCloseController extends Controller
{
    use StreamsCsv;

    public function index(Request $request): View|StreamedResponse
    {
        $admin = $this->admin($request, 'view');
        $f = $request->validate(['location_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $locations = Location::query()->orderBy('name')->get(['id', 'name']);
        $locationId = $f['location_id'] ?? ($locations->count() === 1 ? (string) $locations->first()->id : null);
        $location = $locationId ? $locations->firstWhere('id', $locationId) : null;

        $from = $to = $summary = null;
        if ($location) {
            // El turno empieza donde terminó el último cierre (o a las 00:00 de hoy) y llega hasta ahora
            $from = isset($f['from']) ? Carbon::parse($f['from']) : (CashClose::lastClosedAt($location->id) ?? now()->startOfDay());
            $to = isset($f['to']) ? Carbon::parse($f['to']) : now()->startOfMinute();
            $summary = $to->gt($from) ? CashClose::summarize($location->id, $from, $to, $admin) : null;
        }

        if ($summary && $request->query('export') === 'csv') {
            abort_unless($admin->can(AdminRbac::actionPermissions('coupon-cash-close')['export']['permission']), 403);

            return $this->csv("cierre-caja-{$location->name}-{$from->format('Ymd-Hi')}_{$to->format('Ymd-Hi')}.csv", $location->name, $from, $to, $summary);
        }

        return view('admin.cash-close.index', [
            'locations' => $locations, 'location' => $location,
            'from' => $from?->format('Y-m-d\TH:i'), 'to' => $to?->format('Y-m-d\TH:i'),
            'summary' => $summary,
            'closings' => CashClosing::query()->with(['location', 'closedBy'])->when($location, fn ($q) => $q->where('location_id', $location->id))->orderByDesc('id')->limit(15)->get(),
            'canClose' => $admin->can(AdminRbac::actionPermissions('coupon-cash-close')['close']['permission']) && $admin->allowedCustomerIds() === null,
            'canExport' => $admin->can(AdminRbac::actionPermissions('coupon-cash-close')['export']['permission']),
            'exportUrl' => $summary ? route('admin.cash-close.index', array_merge($request->query(), ['location_id' => $location->id, 'from' => $from->format('Y-m-d\TH:i'), 'to' => $to->format('Y-m-d\TH:i'), 'export' => 'csv'])) : null,
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $admin = $this->admin($request, 'close');
        // Un cierre con el alcance recortado a ciertos clientes sería una foto incompleta
        abort_unless($admin->allowedCustomerIds() === null, 403);

        $data = $request->validate(['location_id' => ['required', 'uuid', 'exists:locations,id'], 'from' => ['required', 'date'], 'to' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:255']]);
        $location = Location::findOrFail($data['location_id']);

        try {
            $closing = CashClose::close($location, Carbon::parse($data['from']), Carbon::parse($data['to']), $data['note'] ?? null, $admin);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['close' => $e->getMessage()]);
        }

        AdminAuditLog::record('cash_close', 'Location', (string) $location->id, [
            'period' => [$closing->period_from->toDateTimeString(), $closing->period_to->toDateTimeString()],
            'redeemed' => (string) $closing->redeemed, 'reversed' => (string) $closing->reversed, 'net' => (string) $closing->net,
        ], $admin);

        return redirect()->route('admin.cash-close.show', $closing)->with('status', 'Caja cerrada.');
    }

    public function show(Request $request, CashClosing $closing): View|StreamedResponse
    {
        $admin = $this->admin($request, 'view');
        $closing->load(['location', 'closedBy']);
        $summary = [
            'totals' => ['redemptions' => $closing->redemptions, 'redeemed' => (float) $closing->redeemed, 'reversed' => (float) $closing->reversed, 'net' => (float) $closing->net, 'topups' => (float) $closing->topups],
            'cashiers' => $closing->details['cashiers'] ?? [], 'dispensers' => $closing->details['dispensers'] ?? [],
        ];

        if ($request->query('export') === 'csv') {
            abort_unless($admin->can(AdminRbac::actionPermissions('coupon-cash-close')['export']['permission']), 403);

            return $this->csv("cierre-caja-{$closing->id}.csv", $closing->location?->name ?? '—', $closing->period_from, $closing->period_to, $summary);
        }

        return view('admin.cash-close.show', [
            'closing' => $closing, 'summary' => $summary,
            'canExport' => $admin->can(AdminRbac::actionPermissions('coupon-cash-close')['export']['permission']),
        ]);
    }

    private function admin(Request $request, string $action): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = $request->user('admin');
        $perm = $action === 'view' ? AdminRbac::permissionsForScreen('coupon-cash-close')['view'] : AdminRbac::actionPermissions('coupon-cash-close')[$action]['permission'];
        abort_unless($admin && $admin->can($perm), 403);

        return $admin;
    }

    private function csv(string $name, string $location, Carbon $from, Carbon $to, array $s): StreamedResponse
    {
        $m = fn ($v) => number_format((float) $v, 2, '.', '');
        $rows = [['CAJERO', $location, $from->format('d/m/Y H:i').' a '.$to->format('d/m/Y H:i'), '', '', '', '', '']];
        foreach ($s['cashiers'] as $c) {
            $rows[] = [$c['name'], $c['redemptions'], $m($c['redeemed']), $m($c['reversed']), $m($c['net']), $m($c['topups']), $c['first_at'], $c['last_at']];
        }
        $t = $s['totals'];
        $rows[] = ['TOTAL', $t['redemptions'], $m($t['redeemed']), $m($t['reversed']), $m($t['net']), $m($t['topups']), '', ''];
        $rows[] = ['', '', '', '', '', '', '', ''];
        $rows[] = ['SURTIDOR', 'Cobros (n.º)', 'Cobrado', 'Reversado', 'Neto', '', '', ''];
        foreach ($s['dispensers'] as $d) {
            $rows[] = [$d['dispenser'] === null ? 'Sin surtidor' : 'Surtidor '.$d['dispenser'], $d['redemptions'], $m($d['redeemed']), $m($d['reversed']), $m($d['net']), '', '', ''];
        }

        // La primera fila ya es el encabezado de la sección de cajeros
        $header = array_shift($rows);

        return $this->streamCsv($name, ['Cajero', 'Cobros (n.º)', 'Cobrado', 'Reversado', 'Neto cobrado', 'Recargado', 'Primer movimiento', 'Último movimiento'], array_merge([['Localidad: '.$header[1], 'Periodo: '.$header[2], '', '', '', '', '', '']], $rows));
    }
}
