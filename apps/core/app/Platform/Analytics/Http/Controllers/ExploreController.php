<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Analytics\Dashboards\DashboardAccess;
use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\DatasetCatalog;
use App\Platform\Analytics\Security\UserPrincipal;
use App\Platform\Identity\Support\UserClock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Analisis data (`/analytics/explore`, area 8): penjelajah satu dataset — padanan *Data analysis mode*
 * Business Central (KA-21) — dijaga `core.analytics.explore.invoke` di gate rute.
 *
 * Prop-nya hanya daftar data yang boleh dibaca pengguna ini (katalog yang sama dengan
 * `GET api/v1/analytics/datasets`) dan hak menyimpan. Query-nya tinggal di query string dan dibaca layar setiap
 * render, jadi server tidak membacanya: mengubah query tidak memuat ulang halaman, dan tautan yang dibagikan
 * membuka analisis yang sama. Isi dataset dan hasilnya diminta layar lewat API, dihitung sebagai pengguna yang
 * membuka.
 */
final class ExploreController extends Controller
{
    public function __construct(
        private readonly DatasetCatalog $catalog,
        private readonly DashboardAccess $access,
        private readonly UserClock $clock,
    ) {}

    public function __invoke(Request $request): Response
    {
        $membership = $this->currentMembership($request);
        $principal = UserPrincipal::fromMembership($membership, $this->clock->timezone($request));

        return Inertia::render('platform/analytics/explore', [
            'datasets' => array_map(
                fn (CompiledDataset $dataset): array => $this->catalog->summary($dataset, $principal),
                $this->catalog->forPrincipal($principal),
            ),
            'abilities' => ['create' => $this->access->mayCreate($membership), 'share' => $this->access->mayShare($membership)],
        ]);
    }
}
