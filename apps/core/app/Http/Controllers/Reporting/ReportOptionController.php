<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Platform\Environment\Support\CurrentWorkspace;
use App\Support\Modules\Contracts\RowVersion;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\ReportOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

/**
 * Opsi dan filter laporan milik pengguna yang sedang masuk (K-24, K-25): yang terakhir dipakai sebagai isian
 * awal halaman filter dan dialog cetak, dan preset bernama.
 *
 * Semua rute menuntut hak menjalankan laporannya. Laporan yang tidak boleh dijalankan dijawab 404, sama
 * dengan laporan yang tidak ada, seperti daftar field-nya.
 *
 * Preset pribadi hanya dapat diubah pemiliknya. Preset bersama dibuat, diubah, dan diarsipkan oleh pemegang
 * `core.report-preset.update`, siapa pun pembuatnya (K-25). Preset yang tidak boleh diubah dijawab 404, sama
 * dengan preset yang tidak ada; meminta preset bersama tanpa permission itu dijawab 403.
 */
class ReportOptionController extends Controller
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly ReportOptions $options,
        private readonly CurrentWorkspace $workspace,
    ) {}

    public function index(Request $request, string $code): JsonResponse
    {
        [$membership, $report] = $this->report($request, $code);

        return response()->json(['data' => $this->options->forMembership(
            $membership,
            $report,
            $this->workspace->legalEntity($request, $membership)?->id,
        )]);
    }

    /** Filter yang baru saja dipakai halaman laporan, dicatat setiap kali pratinjaunya dimuat. */
    public function rememberLastUsed(Request $request, string $code): Response
    {
        [$membership, $report] = $this->report($request, $code);
        $data = $request->validate(['parameters' => ['present', 'array']]);
        $this->options->rememberLastUsed($membership, $report, $data['parameters']);

        return response()->noContent();
    }

    public function storePreset(Request $request, string $code): JsonResponse
    {
        [$membership, $report] = $this->report($request, $code);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parameters' => ['present', 'array'],
            'shared' => ['sometimes', 'boolean'],
        ]);
        $shared = (bool) ($data['shared'] ?? false);
        abort_if($shared && ! ReportOptions::canShare($membership), 403, 'Anda belum boleh membagikan preset ke semua pengguna.');

        $preset = $this->options->createPreset($membership, $report, $data['name'], $data['parameters'], $shared);
        $now = $this->options->now($membership, $this->workspace->legalEntity($request, $membership)?->id);

        return response()->json(['data' => $this->options->present($preset, $membership, $now)], 201);
    }

    public function updatePreset(Request $request, string $code, string $id): JsonResponse
    {
        [$membership, $report] = $this->report($request, $code);
        $preset = $this->options->editablePreset($membership, $report, $id);
        abort_if($preset === null, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'parameters' => ['sometimes', 'array'],
        ]);

        $preset = $this->options->updatePreset(
            $preset,
            $report,
            $membership,
            RowVersion::expected($request),
            $data['name'] ?? null,
            $data['parameters'] ?? null,
        );
        $now = $this->options->now($membership, $this->workspace->legalEntity($request, $membership)?->id);

        return response()->json(['data' => $this->options->present($preset, $membership, $now)], 200, ['ETag' => RowVersion::etag($preset->version)]);
    }

    public function destroyPreset(Request $request, string $code, string $id): Response
    {
        [$membership, $report] = $this->report($request, $code);
        $preset = $this->options->editablePreset($membership, $report, $id);
        abort_if($preset === null, 404);
        $this->options->archivePreset($preset, RowVersion::expected($request));

        return response()->noContent();
    }

    /** @return array{TenantMembership, stdClass} */
    private function report(Request $request, string $code): array
    {
        $membership = $this->currentMembership($request);
        $report = $this->catalog->find($code);
        abort_if($report === null || ! $this->catalog->canRun($membership, $report), 404);

        return [$membership, $report];
    }
}
