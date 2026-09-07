<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Support\CurrentWorkspace;
use App\Support\Reporting\ExportQueue;
use App\Support\Reporting\ExportStatus;
use App\Support\Reporting\ReportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permintaan ekspor milik pengguna yang sedang masuk: buat, pantau, unduh, hapus.
 * Ekspor dikerjakan worker Core; endpoint ini tidak pernah merender sendiri, jadi ia
 * menjawab cepat berapa pun besar datanya.
 */
class ReportExportController extends Controller
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly ExportQueue $exports,
        private readonly CurrentWorkspace $workspace,
    ) {}

    public function page(Request $request): Response
    {
        $membership = $this->currentMembership($request);

        return Inertia::render('reports/exports', [
            'exports' => $this->exports->mine($membership->tenant_id, $membership->user_id),
            'reports' => $this->catalog->forMembership($membership),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $membership = $this->currentMembership($request);

        return response()->json(['data' => $this->exports->mine($membership->tenant_id, $membership->user_id)]);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $report = $this->catalog->find($code);
        abort_if($report === null, 404);
        // Hak membaca datanya diperiksa saat tombol ditekan, bukan sebagai ekspor yang
        // gagal; app memeriksanya lagi saat dataset diminta.
        abort_unless($this->catalog->canRun($membership, $report), 403);

        $data = $request->validate([
            'format' => ['required', 'in:pdf,docx,xlsx'],
            'layout_ref' => ['nullable', 'string', 'max:60'],
            'parameters' => ['nullable', 'array'],
        ]);
        $parameters = array_intersect_key($data['parameters'] ?? [], array_flip($report->parameters));

        $export = $this->exports->enqueue(
            $report,
            $membership,
            $this->workspace->legalEntity($request, $membership)?->id,
            $this->workspace->operatingUnit($request, $membership)?->id,
            $data['format'],
            $data['layout_ref'] ?? null,
            $parameters,
        );

        return response()->json(['data' => $export], 202, ['Location' => url('/api/v1/report-exports/'.$export['id'])]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $export = $this->exports->find($membership->tenant_id, $membership->user_id, $id);
        abort_if($export === null, 404);

        return response()->json(['data' => $this->exports->present($export)]);
    }

    public function download(Request $request, string $id): StreamedResponse
    {
        $membership = $this->currentMembership($request);
        $export = $this->exports->find($membership->tenant_id, $membership->user_id, $id);
        abort_if($export === null || $export->status !== ExportStatus::DONE || ! $export->file_path, 404);
        $disk = Storage::disk((string) config('reporting.disk'));
        abort_unless($disk->exists($export->file_path), 410, 'Berkas hasil ekspor sudah dihapus.');

        return $disk->download($export->file_path, $export->file_name, ['Content-Type' => $export->file_mime]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $membership = $this->currentMembership($request);
        $export = $this->exports->find($membership->tenant_id, $membership->user_id, $id);
        abort_if($export === null, 404);
        $this->exports->delete($export);

        return response()->json(null, 204);
    }
}
