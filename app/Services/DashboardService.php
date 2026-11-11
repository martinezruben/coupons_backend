<?php

namespace App\Services;

use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\SyncLog;
use App\Models\SystemParameter;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /** Horas sin sincronizar a partir de las cuales un dispositivo se considera atrasado (dashboard técnico y mapa). */
    public const STALE_SYNC_HOURS = 4;

    private ?string $locationId = null;

    public function __construct(?string $locationId = null)
    {
        $this->locationId = $locationId;
    }

    public function getKpis(): array
    {
        $todayStart = now()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();
        $yesterdayStart = $todayStart->copy()->subDay();
        $start7 = $todayStart->copy()->subDays(6);
        $start30 = $todayStart->copy()->subDays(29);
        $weekStart = now()->startOfWeek();
        $lastWeekStart = $weekStart->copy()->subWeek();

        // Una sola consulta para todos los periodos, acotada al rango más antiguo
        // que se necesita y con rangos semiabiertos para que use el índice de occurred_at
        $summary = Transaction::query()
            ->where('status', 'PAID')
            ->where('occurred_at', '>=', $start30->min($lastWeekStart))
            ->where('occurred_at', '<', $tomorrowStart)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw('
                SUM(CASE WHEN occurred_at >= ? THEN total ELSE 0 END) as sales_today,
                COUNT(CASE WHEN occurred_at >= ? THEN 1 END) as tx_today,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN total ELSE 0 END) as sales_yesterday,
                COUNT(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN 1 END) as tx_yesterday,
                SUM(CASE WHEN occurred_at >= ? THEN total ELSE 0 END) as sales_7d,
                SUM(CASE WHEN occurred_at >= ? THEN total ELSE 0 END) as sales_30d,
                SUM(CASE WHEN occurred_at >= ? THEN total ELSE 0 END) as sales_this_week,
                SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN total ELSE 0 END) as sales_last_week
            ', [
                $todayStart,
                $todayStart,
                $yesterdayStart, $todayStart,
                $yesterdayStart, $todayStart,
                $start7,
                $start30,
                $weekStart,
                $lastWeekStart, $weekStart,
            ])
            ->first();

        $salesToday = (float) ($summary->sales_today ?? 0);
        $txToday = (int) ($summary->tx_today ?? 0);
        $salesYesterday = (float) ($summary->sales_yesterday ?? 0);
        $txYesterday = (int) ($summary->tx_yesterday ?? 0);
        $sales7d = (float) ($summary->sales_7d ?? 0);
        $sales30d = (float) ($summary->sales_30d ?? 0);
        $salesThisWeek = (float) ($summary->sales_this_week ?? 0);
        $salesLastWeek = (float) ($summary->sales_last_week ?? 0);

        $avgTicketToday = $txToday > 0 ? $salesToday / $txToday : 0.0;
        $avgTicketYesterday = $txYesterday > 0 ? $salesYesterday / $txYesterday : 0.0;

        $weekDeltaPct = $salesLastWeek > 0
            ? round(100 * ($salesThisWeek - $salesLastWeek) / $salesLastWeek, 1)
            : ($salesThisWeek > 0 ? 100.0 : null);

        return [
            'salesToday' => $salesToday,
            'txToday' => $txToday,
            'salesYesterday' => $salesYesterday,
            'txYesterday' => $txYesterday,
            'avgTicketToday' => $avgTicketToday,
            'avgTicketYesterday' => $avgTicketYesterday,
            'sales7d' => $sales7d,
            'sales30d' => $sales30d,
            'salesThisWeek' => $salesThisWeek,
            'salesLastWeek' => $salesLastWeek,
            'weekDeltaPct' => $weekDeltaPct,
        ];
    }

    /**
     * Sincronizaciones de los últimos 7 días.
     *
     * @return array{success: int, failed: int, okPct: float|null}
     */
    public function getSyncStats7d(): array
    {
        $byStatus = SyncLog::query()
            ->where('started_at', '>=', now()->subDays(7))
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $success = (int) ($byStatus['SUCCESS'] ?? 0);
        $failed = (int) ($byStatus['FAILED'] ?? 0);
        $total = $success + $failed;

        return [
            'success' => $success,
            'failed' => $failed,
            'okPct' => $total > 0 ? round(100 * $success / $total, 1) : null,
        ];
    }

    public function getDailySalesTrend(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $dateSql = $this->sqlDateColumn('occurred_at');
        $rows = Transaction::query()
            ->where('status', 'PAID')
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("{$dateSql} as d, SUM(total) as sales, COUNT(*) as cnt")
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->groupBy(DB::raw($dateSql))
            ->orderBy(DB::raw($dateSql))
            ->get()
            ->keyBy('d');

        $labels = [];
        $sales = [];
        $transactions = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->translatedFormat('d M');
            $row = $rows->get($key);
            $sales[] = $row ? (float) $row->sales : 0.0;
            $transactions[] = $row ? (int) $row->cnt : 0;
            $cursor->addDay();
        }

        return [
            'labels' => $labels,
            'sales' => $sales,
            'transactions' => $transactions,
        ];
    }

    /**
     * Margen de los últimos N días sobre las líneas con costo congelado, más la cobertura.
     *
     * @return array{revenue: float, cost: float, margin: ?float, margin_pct: ?float, coverage_pct: ?float}
     */
    public function getMarginSummary(int $days): array
    {
        return (new ReportService)->getMarginSummary(now()->subDays($days - 1)->startOfDay(), now()->endOfDay(), $this->locationId);
    }

    /**
     * Ventas cobradas por día de la semana (lunes primero) y hora del día, para el mapa de calor.
     * La BD guarda hora de muro local, así que se agrupa sin convertir zona horaria.
     *
     * @return array{days: list<string>, hours: list<string>, sales: list<list<float>>, tickets: list<list<int>>}
     */
    public function getSalesHeatmap(int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        [$dowSql, $hourSql] = match (DB::getDriverName()) {
            // %w: 0 = domingo; HOUR/DAYOFWEEK de MySQL: 1 = domingo
            'sqlite' => ["CAST(strftime('%w', occurred_at) AS INTEGER)", "CAST(strftime('%H', occurred_at) AS INTEGER)"],
            default => ['(DAYOFWEEK(occurred_at) - 1)', 'HOUR(occurred_at)'],
        };

        $rows = Transaction::query()
            ->where('status', 'PAID')
            ->where('occurred_at', '>=', $since)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw("{$dowSql} as dow, {$hourSql} as hr, SUM(total) as sales, COUNT(*) as cnt")
            ->groupBy(DB::raw($dowSql), DB::raw($hourSql))
            ->get();

        // Filas lunes..domingo (la columna dow trae 0 = domingo)
        $sales = array_fill(0, 7, array_fill(0, 24, 0.0));
        $tickets = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($rows as $row) {
            $day = ((int) $row->dow + 6) % 7;
            $hour = (int) $row->hr;
            $sales[$day][$hour] = round((float) $row->sales, 2);
            $tickets[$day][$hour] = (int) $row->cnt;
        }

        return [
            'days' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
            'hours' => array_map(fn (int $h) => sprintf('%02d', $h), range(0, 23)),
            'sales' => $sales,
            'tickets' => $tickets,
        ];
    }

    public function getSalesByFamily(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $top = 5;

        $rows = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->leftJoin('products', 'transaction_items.product_id', '=', 'products.id')
            ->leftJoin('subfamilies', 'products.subfamily_id', '=', 'subfamilies.id')
            ->leftJoin('families', 'subfamilies.family_id', '=', 'families.id')
            ->where('transactions.status', 'PAID')
            ->where('transactions.occurred_at', '>=', $since)
            ->when($this->locationId, fn ($q) => $q->where('transactions.location_id', $this->locationId))
            ->selectRaw('families.id as id, MAX(families.name) as name, SUM(transaction_items.line_total) as total')
            ->groupBy('families.id')
            ->get();

        $unassigned = (float) $rows->whereNull('id')->sum('total');
        $families = $rows->whereNotNull('id')
            ->sort(fn ($a, $b) => [(float) $b->total, (string) $a->name] <=> [(float) $a->total, (string) $b->name])
            ->values();

        $labels = [];
        $series = [];
        foreach ($families->take($top) as $row) {
            $labels[] = (string) $row->name;
            $series[] = (float) $row->total;
        }

        $others = (float) $families->slice($top)->sum('total');
        if ($others > 0) {
            $labels[] = 'Otros';
            $series[] = $others;
        }
        if ($unassigned > 0) {
            $labels[] = 'Sin familia';
            $series[] = $unassigned;
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    public function getSyncSuccessFailedByDay(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $dateSql = $this->sqlDateColumn('started_at');
        $raw = SyncLog::query()
            ->whereBetween('started_at', [$from, $to])
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw("{$dateSql} as d, status, COUNT(*) as c")
            ->groupBy(DB::raw($dateSql), 'status')
            ->get();

        $byDay = [];
        foreach ($raw as $row) {
            $d = Carbon::parse($row->d)->toDateString();
            $byDay[$d] ??= ['SUCCESS' => 0, 'FAILED' => 0];
            $byDay[$d][$row->status] = (int) $row->c;
        }

        $categories = [];
        $success = [];
        $failed = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $categories[] = $cursor->translatedFormat('d M');
            $success[] = $byDay[$key]['SUCCESS'] ?? 0;
            $failed[] = $byDay[$key]['FAILED'] ?? 0;
            $cursor->addDay();
        }

        return [
            'categories' => $categories,
            'success' => $success,
            'failed' => $failed,
        ];
    }

    public function getSalesByPaymentMethod(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $rows = Transaction::query()
            ->where('status', 'PAID')
            ->where('occurred_at', '>=', $since)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->join('transaction_payments', 'transactions.id', '=', 'transaction_payments.transaction_id')
            ->selectRaw('transaction_payments.payment_method, SUM(transaction_payments.amount) as total')
            ->groupBy('transaction_payments.payment_method')
            ->orderByDesc('total')
            ->get();

        $methodLabels = PaymentMethod::labelsFor($rows->pluck('payment_method'));

        return [
            'labels' => $rows->pluck('payment_method')->map(fn ($m) => $methodLabels[$m] ?? $m)->values()->all(),
            'series' => $rows->pluck('total')->map(fn ($v) => round((float) $v, 2))->values()->all(),
        ];
    }

    public function getTopProductsBySales(int $days, int $limit): array
    {
        $since = now()->subDays($days)->startOfDay();

        $rows = DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->leftJoin('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.status', 'PAID')
            ->where('transactions.occurred_at', '>=', $since)
            ->whereNotNull('transaction_items.product_id')
            ->when($this->locationId, fn ($q) => $q->where('transactions.location_id', $this->locationId))
            ->selectRaw("transaction_items.product_id, MAX(COALESCE(NULLIF(products.name, ''), NULLIF(transaction_items.product_name, ''))) as name, SUM(transaction_items.qty) as qty, SUM(transaction_items.line_total) as total")
            ->groupBy('transaction_items.product_id')
            ->orderByDesc('total')
            ->limit($limit * 2)
            ->get();

        $rows = $rows->filter(fn ($row) => $row->name !== null && trim((string) $row->name) !== '')->take($limit);

        if ($rows->isEmpty()) {
            return [];
        }

        // Participación sobre todo lo vendido en el periodo, no sobre el top
        $periodTotal = (float) DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', 'PAID')
            ->where('transactions.occurred_at', '>=', $since)
            ->when($this->locationId, fn ($q) => $q->where('transactions.location_id', $this->locationId))
            ->sum('transaction_items.line_total');

        return $rows
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'qty' => (float) $row->qty,
                'total' => (float) $row->total,
                'pct' => $periodTotal > 0 ? round(100 * (float) $row->total / $periodTotal, 1) : 0.0,
            ])
            ->all();
    }

    public function getTopLocationsBySales(int $days, int $limit): array
    {
        $since = now()->subDays($days)->startOfDay();

        $totals = Transaction::query()
            ->where('status', 'PAID')
            ->where('occurred_at', '>=', $since)
            ->selectRaw('location_id, SUM(total) as total')
            ->groupBy('location_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        if ($totals->isEmpty()) {
            return [];
        }

        // Participación sobre todas las ventas del periodo, no sobre el top
        $periodTotal = (float) Transaction::query()
            ->where('status', 'PAID')
            ->where('occurred_at', '>=', $since)
            ->sum('total');

        $locationIds = $totals->pluck('location_id')->all();
        $names = Location::query()->whereIn('id', $locationIds)->pluck('name', 'id');

        $out = [];
        foreach ($totals as $row) {
            $t = (float) $row->total;
            $out[] = [
                'name' => (string) ($names[$row->location_id] ?? '—'),
                'total' => $t,
                'pct' => $periodTotal > 0 ? round(100 * $t / $periodTotal, 1) : 0.0,
            ];
        }

        return $out;
    }

    public function getRecentActivity(int $limit): array
    {
        $sync = SyncLog::query()
            ->with(['location:id,name', 'device:id,name,device_fingerprint'])
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->orderByDesc('started_at')
            ->limit($limit * 2)
            ->get();

        $api = ApiRequestLog::query()
            ->with(['location:id,name', 'device:id,name,device_fingerprint'])
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get();

        $merged = collect();

        foreach ($sync as $log) {
            $at = $log->started_at;
            $merged->push([
                'sort' => $at ? $at->getTimestamp() : 0,
                'location' => $log->location?->name ?? '—',
                'device' => $log->device?->name ?: ($log->device?->device_fingerprint ?? '—'),
                'direction' => $log->operation === 'PUSH' ? 'Push' : 'Pull',
                'time_human' => $at ? $at->copy()->locale('es')->diffForHumans() : '—',
                'tone' => $log->status === 'SUCCESS' ? 'emerald' : 'rose',
            ]);
        }

        foreach ($api as $row) {
            $at = $row->created_at;
            $merged->push([
                'sort' => $at ? $at->getTimestamp() : 0,
                'location' => $row->location?->name ?? '—',
                'device' => $row->device?->name ?: ($row->device?->device_fingerprint ?? $row->device_fingerprint ?? '—'),
                'direction' => $this->apiSyncDirectionLabel($row->path),
                'time_human' => $at ? $at->copy()->locale('es')->diffForHumans() : '—',
                'tone' => $row->response_status >= 200 && $row->response_status < 400 ? 'sky' : 'amber',
            ]);
        }

        return $merged->sortByDesc('sort')
            ->take($limit)
            ->map(fn (array $e) => [
                'location' => $e['location'],
                'device' => $e['device'],
                'direction' => $e['direction'],
                'time_human' => $e['time_human'],
                'tone' => $e['tone'],
            ])
            ->values()
            ->all();
    }

    /**
     * Conteos de inventario técnico, acotados a la localidad si hay filtro.
     *
     * @return array{locations: int, devices: int, licenses: int}
     */
    public function getInventoryCounts(): array
    {
        return [
            'locations' => Location::query()
                ->where('is_active', true)
                ->when($this->locationId, fn ($q) => $q->whereKey($this->locationId))
                ->count(),
            'devices' => Device::query()
                ->where('is_enabled', true)
                ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
                ->count(),
            'licenses' => License::query()
                ->where('status', 'ACTIVE')
                ->when($this->locationId, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('location_id', $this->locationId)))
                ->count(),
        ];
    }

    public function getLocationsInContingency(): array
    {
        return Location::query()
            ->where('is_active', true)
            ->whereNotNull('contingency_started_at')
            ->when($this->locationId, fn ($q) => $q->whereKey($this->locationId))
            ->orderBy('contingency_started_at')
            ->get(['name', 'contingency_started_at'])
            ->map(fn ($loc) => [
                'name' => $loc->name,
                'since' => $loc->contingency_started_at->copy()->locale('es')->diffForHumans(),
            ])
            ->all();
    }

    public function getDevicesNotSyncedSince(int $hours = self::STALE_SYNC_HOURS): array
    {
        $since = now()->subHours($hours);

        return Device::query()
            ->where('is_enabled', true)
            ->where(function ($q) use ($since) {
                $q->whereNull('last_sync_at')->orWhere('last_sync_at', '<', $since);
            })
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->with('location:id,name')
            ->orderByRaw('COALESCE(last_sync_at, created_at) ASC')
            ->get(['id', 'name', 'location_id', 'last_sync_at', 'created_at'])
            ->map(function ($dev) {
                $last = $dev->last_sync_at ?? $dev->created_at;

                return [
                    'name' => $dev->name ?: $dev->id,
                    'location' => $dev->location?->name ?? '—',
                    'hours_ago' => (int) ceil($last->diffInMinutes(now()) / 60),
                ];
            })
            ->all();
    }

    /**
     * Dispositivos habilitados cuya versión de app reportada es menor que la mínima configurada.
     * Los que nunca reportaron versión no cuentan: aún no tienen una app que la envíe.
     *
     * @return list<array{name: string, location: string, version: string, minimum: string}>
     */
    public function getOutdatedDevices(): array
    {
        $minimum = AppVersion::normalize(SystemParameter::query()->value('min_app_version'));
        if ($minimum === null) {
            return [];
        }

        return Device::query()
            ->where('is_enabled', true)
            ->whereNotNull('app_version')
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->with('location:id,name')
            ->orderBy('app_version')
            ->get(['id', 'name', 'location_id', 'app_version'])
            ->filter(fn ($dev) => AppVersion::isOutdated($dev->app_version, $minimum))
            ->map(fn ($dev) => [
                'name' => $dev->name ?: $dev->id,
                'location' => $dev->location?->name ?? '—',
                'version' => $dev->app_version,
                'minimum' => $minimum,
            ])
            ->values()
            ->all();
    }

    /**
     * Usuarios POS activos que llegan a las tablets pero cuyo PIN no sirve sin conexión
     * (sin hash SHA-384 válido). Se corrigen volviendo a guardar su contraseña.
     *
     * @return array{count: int, rows: list<array{username: string, name: string, role: string, location: string}>}
     */
    public function getUsersWithoutOfflinePin(int $limit = 50): array
    {
        $query = User::query()
            ->withoutOfflinePin()
            ->reachingDevices($this->locationId)
            ->where('users.is_active', true);

        return [
            'count' => (clone $query)->count(),
            'rows' => $query->with(['locations:id,name', 'location:id,name'])
                ->orderBy('username')
                ->limit($limit)
                ->get()
                ->map(fn (User $u) => [
                    'username' => $u->username,
                    'name' => $u->full_name ?: '—',
                    'role' => $u->role,
                    'location' => $u->locations->pluck('name')->push($u->location?->name)->filter()->unique()->implode(', ') ?: '—',
                ])
                ->all(),
        ];
    }

    public function getLicensesExpiringWithin(int $days = 7): array
    {
        $until = now()->addDays($days)->endOfDay();

        return License::query()
            ->where('status', 'ACTIVE')
            ->whereBetween('valid_to', [now(), $until])
            ->when($this->locationId, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('location_id', $this->locationId)))
            ->with(['device:id,name,location_id', 'device.location:id,name'])
            ->orderBy('valid_to')
            ->get()
            ->map(fn ($lic) => [
                'device' => $lic->device?->name ?: $lic->device_id,
                'location' => $lic->device?->location?->name ?? '—',
                'expires_in' => $lic->valid_to->copy()->locale('es')->diffForHumans(),
            ])
            ->all();
    }

    public function getSyncFailuresLast24h(): array
    {
        $since = now()->subDay();

        $result = SyncLog::query()
            ->where('started_at', '>=', $since)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', ['FAILED'])
            ->first();

        return [
            'count' => (int) ($result->failed ?? 0),
            'total' => (int) ($result->total ?? 0),
        ];
    }

    public function getVoidedTransactions(): array
    {
        $todayStart = now()->startOfDay();
        $weekStart = $todayStart->copy()->subDays(6);

        $todayRow = Transaction::query()
            ->where('status', 'VOIDED')
            ->whereBetween('occurred_at', [$todayStart, $todayStart->copy()->endOfDay()])
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')
            ->first();

        $weekRow = Transaction::query()
            ->where('status', 'VOIDED')
            ->where('occurred_at', '>=', $weekStart)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total')
            ->first();

        return [
            'today' => [
                'count' => (int) ($todayRow->count ?? 0),
                'total' => (float) ($todayRow->total ?? 0),
            ],
            'week' => [
                'count' => (int) ($weekRow->count ?? 0),
                'total' => (float) ($weekRow->total ?? 0),
            ],
        ];
    }

    /** Pull/Push según ruta; si no es sync, etiqueta corta. */
    private function apiSyncDirectionLabel(string $path): string
    {
        $p = strtolower($path);

        if (str_contains($p, 'sync/pull') || str_ends_with($p, '/pull')) {
            return 'Pull';
        }

        if (str_contains($p, 'sync/push') || str_ends_with($p, '/push')) {
            return 'Push';
        }

        return 'API';
    }

    private function sqlDateColumn(string $column): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            default => "DATE({$column})",
        };
    }
}
