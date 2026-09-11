<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Support\CurrentWorkspace;
use App\Support\Reporting\PrintIdentityStore;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\SumberLaporan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Katalog laporan untuk pengguna yang sedang masuk: laporan dari app yang siap dibuka,
 * dan placeholder tiap laporan (diminta ke app). Dipakai dialog cetak di Shell dan
 * halaman Layout laporan.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly SumberLaporan $client,
        private readonly CurrentWorkspace $workspace,
        private readonly PrintIdentityStore $identities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        return response()->json(['data' => $this->catalog->forMembership($membership)]);
    }

    public function fields(Request $request, string $code): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $report = $this->catalog->find($code);
        abort_if($report === null || ! $this->catalog->canRun($membership, $report), 404);

        $definition = $this->client->definition(
            $report,
            $membership,
            $this->workspace->legalEntity($request, $membership)?->id,
            $this->workspace->operatingUnit($request, $membership)?->id,
        );

        // Placeholder kop disediakan Core untuk semua laporan; ditampilkan setelah
        // placeholder milik app supaya pembuat layout melihat keduanya di satu daftar.
        return response()->json([
            'data' => [...$definition['fields'], ...$this->identities->catalog()],
            'meta' => ['parameters' => $definition['parameters']],
        ]);
    }
}
