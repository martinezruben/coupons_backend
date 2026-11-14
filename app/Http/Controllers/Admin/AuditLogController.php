<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Support\AdminRbac;
use App\Support\AuditLabels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Auditoría del panel: quién hizo qué y cuándo, con el dato antes y después y el detalle técnico de cada movimiento. */
class AuditLogController extends Controller
{
    use StreamsCsv;

    public function index(Request $request): View|StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('audit-log')['view']), 403);

        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:30'],
            'entity_type' => ['nullable', 'string', 'max:60'],
            'kind' => ['nullable', 'in:all,changes,clicks'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $kind = $f['kind'] ?? 'all';

        $query = AdminAuditLog::query()
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('summary', 'like', "%{$v}%")->orWhere('entity_id', $v)->orWhere('admin_name', 'like', "%{$v}%")->orWhere('route', 'like', "%{$v}%")))
            ->when($f['user'] ?? null, fn ($q, $v) => $q->where('admin_user_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['entity_type'] ?? null, fn ($q, $v) => $q->where('entity_type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            // «Cambios de datos» = lo que modificó información; «Clics» = navegación y acciones enviadas
            ->when($kind === 'changes', fn ($q) => $q->whereNotIn('action', ['view', 'submit']))
            ->when($kind === 'clicks', fn ($q) => $q->whereIn('action', ['view', 'submit']))
            ->orderByDesc('created_at')->orderByDesc('id');

        if ($request->query('export') === 'csv') {
            $rows = (clone $query)->limit(5000)->get()->map(fn (AdminAuditLog $l) => [
                $l->created_at?->format('Y-m-d H:i:s'), $l->admin_name, AuditLabels::action($l->action), $l->summary, AuditLabels::entity($l->entity_type), $l->entity_id,
                collect($l->changes ?? [])->map(fn ($p, $k) => $k.': '.$this->show($p[0] ?? null).' → '.$this->show($p[1] ?? null))->implode(' | '),
                $l->method, $l->route, $l->status_code, $l->ip,
            ])->all();

            return $this->streamCsv('auditoria-'.now()->format('Ymd-His').'.csv',
                ['Fecha y hora', 'Usuario', 'Movimiento', 'Detalle', 'Módulo', 'Registro', 'Cambios (antes → después)', 'Método', 'Ruta', 'Resultado', 'IP'], $rows);
        }

        $logs = $query->paginate(30)->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'f' => $f + ['kind' => $kind],
            'users' => AdminUser::query()->orderBy('name')->get(['id', 'name']),
            'entities' => AdminAuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type')->mapWithKeys(fn ($t) => [$t => AuditLabels::entity($t)]),
            'actions' => AuditLabels::ACTIONS,
            'exportUrl' => route('admin.audit.index', array_merge($request->query(), ['export' => 'csv'])),
        ]);
    }

    private function show(mixed $v): string
    {
        return $v === null || $v === '' ? '—' : (string) $v;
    }
}
