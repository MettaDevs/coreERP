<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Http\Presenters\DashboardPresenter;
use App\Platform\Analytics\Models\Dashboard;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Shell dasbor analitik (area 6.8): daftar dasbor di `/analytics` dan satu dasbor di
 * `/analytics/dashboards/{dashboard}`, dijaga `core.analytics.dashboard.read` di gate rute. Komponen layarnya
 * (`platform/analytics/index` dan `platform/analytics/dashboard`) dibuat area 7.
 *
 * Prop-nya bentuk yang sama dengan API layar (`DashboardPresenter`), supaya halaman terbuka tanpa permintaan
 * tambahan dan memuat ulang lewat API yang sama. Data widget tidak ikut: setiap widget memintanya sendiri saat
 * terlihat, dihitung sebagai yang melihat. Dasbor yang tidak boleh dilihat dijawab 404 seperti di API.
 */
final class DashboardPageController extends Controller
{
    public function __construct(
        private readonly DashboardAccess $access,
        private readonly DashboardPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $membership = $this->currentMembership($request);

        return Inertia::render('platform/analytics/index', [
            'dashboards' => $this->presenter->list($membership),
            'abilities' => $this->abilities($membership),
        ]);
    }

    public function show(Request $request, Dashboard $dashboard): Response
    {
        $membership = $this->currentMembership($request);
        $this->access->authorizeView($membership, $dashboard);

        return Inertia::render('platform/analytics/dashboard', [
            'dashboard' => $this->presenter->detail($dashboard, $membership),
            'abilities' => $this->abilities($membership),
        ]);
    }

    /** @return array{create: bool, share: bool} */
    private function abilities(TenantMembership $membership): array
    {
        return ['create' => $this->access->mayCreate($membership), 'share' => $this->access->mayShare($membership)];
    }
}
