<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityAlert;
use App\Support\AdminRbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityAlertController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin && $admin->can(AdminRbac::permissionsForScreen('coupon-security-alerts')['view']), 403);

        $show = $request->query('show') === 'all' ? 'all' : 'open';
        $alerts = SecurityAlert::query()->when($show === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when(in_array($request->query('severity'), ['high', 'medium', 'low'], true), fn ($q) => $q->where('severity', $request->query('severity')))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin.security-alerts.index', [
            'alerts' => $alerts, 'show' => $show, 'severity' => $request->query('severity'),
            'canResolve' => $admin->can(AdminRbac::actionPermissions('coupon-security-alerts')['resolve']['permission']),
        ]);
    }

    public function resolve(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user('admin')->can(AdminRbac::actionPermissions('coupon-security-alerts')['resolve']['permission']), 403);
        SecurityAlert::whereKey($id)->whereNull('resolved_at')->update(['resolved_at' => now()]);

        return back()->with('status', 'Alerta marcada como revisada.');
    }
}
