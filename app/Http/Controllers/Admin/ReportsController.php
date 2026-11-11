<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\ReportService;
use App\Support\AdminRbac;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportsController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function productsBestSellers(Request $request): View
    {
        $this->authorizeView('products-best-sellers');

        [$dateFrom, $dateTo] = $this->period($request);
        $locationId = $request->input('location_id');

        $products = $this->reportService->getProductsBestSellers($dateFrom, $dateTo, $locationId);

        return view('admin.reports.products-best-sellers', [
            'products' => $products,
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'locationId' => $locationId,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function productsNoMovement(Request $request): View
    {
        $this->authorizeView('products-no-movement');

        [$dateFrom, $dateTo] = $this->period($request);
        $locationId = $request->input('location_id');

        return view('admin.reports.products-no-movement', [
            'products' => $this->reportService->getProductsWithoutMovement($dateFrom, $dateTo, $locationId),
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'locationId' => $locationId,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function productsMargin(Request $request): View
    {
        $this->authorizeView('products-margin');

        [$dateFrom, $dateTo] = $this->period($request);
        $locationId = $request->input('location_id');

        return view('admin.reports.products-margin', [
            'products' => $this->reportService->getProductsMargin($dateFrom, $dateTo, $locationId),
            'summary' => $this->reportService->getMarginSummary($dateFrom, $dateTo, $locationId),
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'locationId' => $locationId,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function paymentMethodsReport(Request $request): View
    {
        $this->authorizeView('payment-methods-report');

        [$dateFrom, $dateTo] = $this->period($request);

        $locationId = $request->input('location_id');

        $methods = $this->reportService->getPaymentMethodsReport($dateFrom, $dateTo, $locationId);

        return view('admin.reports.payment-methods', [
            'methods' => $methods,
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'locationId' => $locationId,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function usersPerformanceReport(Request $request): View
    {
        $this->authorizeView('users-performance-report');

        [$dateFrom, $dateTo] = $this->period($request);
        $locationId = $request->input('location_id');

        $users = $this->reportService->getUsersPerformanceReport($dateFrom, $dateTo, $locationId);

        return view('admin.reports.users-performance', [
            'users' => $users,
            'dateFrom' => $dateFrom->format('Y-m-d'),
            'dateTo' => $dateTo->format('Y-m-d'),
            'locationId' => $locationId,
            'locations' => Location::query()->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    /**
     * Periodo del filtro por días completos: «hasta» incluye todo ese día.
     * Fechas inválidas o invertidas vuelven al último mes.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $validator = validator($request->only('date_from', 'date_to'), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
        $input = $validator->fails() ? [] : $validator->validated();

        $from = ! empty($input['date_from']) ? Carbon::parse($input['date_from'])->startOfDay() : now()->subMonth()->startOfDay();
        $to = ! empty($input['date_to']) ? Carbon::parse($input['date_to'])->endOfDay() : now()->endOfDay();

        return $from->lte($to) ? [$from, $to] : [now()->subMonth()->startOfDay(), now()->endOfDay()];
    }

    private function authorizeView(string $screen): void
    {
        $user = auth('admin')->user();
        abort_unless($user && $user->can(AdminRbac::permissionsForScreen($screen)['view']), 403);
    }
}
