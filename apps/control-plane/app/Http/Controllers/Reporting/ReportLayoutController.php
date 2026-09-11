<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Support\CurrentWorkspace;
use App\Support\Reporting\LayoutRef;
use App\Support\Reporting\LayoutStore;
use App\Support\Reporting\PrintIdentityStore;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\SumberLaporan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Layout per laporan: halaman pengaturan, daftar, unggah, ganti, hapus, dan default.
 * Padanan Report Layouts dan Report Selections Business Central, untuk semua app.
 *
 * Membaca daftar cukup dengan hak menjalankan laporan itu, karena dialog cetak pun
 * perlu memilih layout. Mengubahnya butuh hak admin tenant, supaya hak mencetak dapat
 * diberikan tanpa hak mengganti kop surat perusahaan.
 */
class ReportLayoutController extends Controller
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly LayoutStore $layouts,
        private readonly SumberLaporan $client,
        private readonly CurrentWorkspace $workspace,
        private readonly PrintIdentityStore $identities,
    ) {}

    public function page(Request $request): Response
    {
        $membership = $this->currentMembership($request);

        return Inertia::render('settings/report-layouts', [
            'canManage' => $request->user()->can('manage-report-layouts'),
            'reports' => $this->catalog->forMembership($membership),
            'legalEntity' => $this->workspace->legalEntity($request, $membership)?->only(['id', 'name']),
        ]);
    }

    public function index(Request $request, string $code): JsonResponse
    {
        [$membership, $report] = $this->report($request, $code);

        return $this->listing($request, $membership, $report);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-layouts'), 403);
        [$membership, $report] = $this->report($request, $code);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('reporting.max_layout_kb')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scope' => ['required', 'in:tenant,legal_entity'],
        ]);
        $legalEntityId = $this->workspace->legalEntity($request, $membership)?->id;
        abort_if($data['scope'] === 'legal_entity' && $legalEntityId === null, 422, 'Konteks aktif tidak memiliki legal entity.');

        $result = $this->layouts->store(
            $report,
            $membership->tenant_id,
            $data['scope'] === 'legal_entity' ? $legalEntityId : null,
            $request->user()->id,
            $request->file('file'),
            $data['name'],
            $data['description'] ?? null,
            $this->knownKeys($request, $membership, $report),
        );

        return response()->json(['data' => $result['layout'], 'meta' => ['unknown_placeholders' => $result['unknown_placeholders']]], 201);
    }

    public function update(Request $request, string $code, string $id): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-layouts'), 403);
        [$membership, $report] = $this->report($request, $code);
        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:'.(int) config('reporting.max_layout_kb')],
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $result = $this->layouts->update(
            $report,
            $membership->tenant_id,
            $this->workspace->legalEntity($request, $membership)?->id,
            $id,
            $data['name'] ?? null,
            $data['description'] ?? null,
            $request->has('description'),
            $request->file('file'),
            $request->file('file') ? $this->knownKeys($request, $membership, $report) : [],
        );

        return response()->json(['data' => $result['layout'], 'meta' => ['unknown_placeholders' => $result['unknown_placeholders']]]);
    }

    public function destroy(Request $request, string $code, string $id): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-layouts'), 403);
        [$membership, $report] = $this->report($request, $code);
        $this->layouts->delete($report, $membership->tenant_id, $this->workspace->legalEntity($request, $membership)?->id, $id);

        return response()->json(null, 204);
    }

    /** Berkas layout untuk diunduh, disunting di Word/Excel, lalu diunggah kembali. */
    public function file(Request $request, string $code, string $ref): BinaryFileResponse
    {
        abort_unless($request->user()->can('manage-report-layouts'), 403);
        [$membership, $report] = $this->report($request, $code);
        $legalEntityId = $this->workspace->legalEntity($request, $membership)?->id;
        abort_unless(LayoutRef::isValid($ref) && $this->layouts->exists($report, $membership->tenant_id, $legalEntityId, $ref), 404);

        $layout = $this->layouts->resolve(
            $report, $membership->tenant_id, $legalEntityId, $ref, $membership, $this->workspace->operatingUnit($request, $membership)?->id,
        );
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $layout->name) ?? 'layout';

        return response()->download($layout->localPath, "{$report->code}-{$name}.{$layout->format}")->deleteFileAfterSend(true);
    }

    public function setDefault(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can('manage-report-layouts'), 403);
        [$membership, $report] = $this->report($request, $code);
        $data = $request->validate([
            'layout_ref' => ['nullable', 'string', 'max:60'],
            'scope' => ['required', 'in:tenant,legal_entity'],
        ]);
        $legalEntityId = $this->workspace->legalEntity($request, $membership)?->id;
        abort_if($data['scope'] === 'legal_entity' && $legalEntityId === null, 422, 'Konteks aktif tidak memiliki legal entity.');

        $this->layouts->setDefault($report, $membership->tenant_id, $legalEntityId, $data['layout_ref'] ?? null, $data['scope'] === 'legal_entity' ? $legalEntityId : null);

        return $this->listing($request, $membership, $report);
    }

    /** @return array{0: TenantMembership, 1: stdClass} */
    private function report(Request $request, string $code): array
    {
        $membership = $this->currentMembership($request);
        $report = $this->catalog->find($code);
        // Laporan yang tidak boleh dijalankan pengguna ini dijawab 404, bukan 403: keberadaan
        // laporan app yang tidak ia pakai bukan urusannya.
        abort_if($report === null || ! $this->catalog->canRun($membership, $report), 404);

        return [$membership, $report];
    }

    private function listing(Request $request, TenantMembership $membership, stdClass $report): JsonResponse
    {
        $legalEntityId = $this->workspace->legalEntity($request, $membership)?->id;

        return response()->json([
            'data' => $this->layouts->list($report, $membership->tenant_id, $legalEntityId),
            'meta' => ['default_ref' => $this->layouts->defaultRef($report, $membership->tenant_id, $legalEntityId)],
        ]);
    }

    /** @return list<string> */
    private function knownKeys(Request $request, TenantMembership $membership, stdClass $report): array
    {
        $definition = $this->client->definition(
            $report, $membership, $this->workspace->legalEntity($request, $membership)?->id, $this->workspace->operatingUnit($request, $membership)?->id,
        );

        return array_map(fn (array $field): string => (string) $field['key'], [...$definition['fields'], ...$this->identities->catalog()]);
    }
}
