<?php

namespace App\Http\Controllers\transaksi\DokumenSiklusAset;

use App\Http\Controllers\Controller;
use App\Services\NumberSequenceClient;
use App\Services\WorkflowClient;
use App\Support\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class DokumenSiklusAsetController extends Controller
{
    private const TYPES = ['permintaan-pembelian-aset', 'pemeliharaan-aset', 'dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'];

    public function indexByRoute(Request $request): JsonResponse { return $this->index($request, $this->typeFromRequest($request)); }
    public function storeByRoute(Request $request, NumberSequenceClient $numbers, WorkflowClient $workflow): JsonResponse { return $this->store($request, $this->typeFromRequest($request), $numbers, $workflow); }

    public function index(Request $request, string $type): JsonResponse
    {
        $this->guard($request, $type, 'read');
        $query = DB::table('tr_dokumen_siklus_aset')->where(['tenant_id' => $this->tenant($request), 'jenis_dokumen' => $type]);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');
        return response()->json(['data' => $query->latest('created_at')->get()]);
    }

    public function store(Request $request, string $type, NumberSequenceClient $numbers, WorkflowClient $workflow): JsonResponse
    {
        $this->guard($request, $type, 'create');
        $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'], 'responsible_org_unit_id' => ['required', 'ulid'], 'tanggal' => ['required', 'date'],
            'asset_id' => ['nullable', 'ulid', Rule::exists('tr_penerimaan_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'nilai' => ['nullable', 'numeric', 'min:0'], 'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        if (in_array($type, ['pemeliharaan-aset', 'dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'], true)) validator($data, ['asset_id' => ['required']])->validate();
        if ($data['asset_id'] ?? null) {
            $asset = app(OrganizationScope::class)->assetQuery(
                DB::table('tr_penerimaan_aset')->where(['tenant_id' => $tenant, 'id' => $data['asset_id']]),
                $request,
            )->first();
            abort_unless($asset, 404);
            abort_unless($asset->legal_entity_id === $data['legal_entity_id'] && $asset->responsible_org_unit_id === $data['responsible_org_unit_id'], 422, 'Entitas dan unit kerja dokumen harus sama dengan aset.');
            if ($type === 'dekomisioning-aset') abort_if(in_array($asset->lifecycle_state, ['decommissioned', 'disposed'], true), 422, 'Aset ini sudah tidak aktif atau sudah dilepas.');
            if (in_array($type, ['penjualan-aset', 'pemusnahan-aset'], true)) abort_unless($asset->lifecycle_state === 'decommissioned', 422, 'Aset harus disetujui untuk dekomisioning sebelum dijual atau dimusnahkan.');
        }
        $existing = DB::table('tr_dokumen_siklus_aset')->where(['tenant_id' => $tenant, 'creation_key' => $key])->first();
        if ($existing) {
            if ($type === 'dekomisioning-aset' && $existing->workflow_instance_id === null) $this->submitWorkflow($existing, $tenant, $key, $workflow);
            return response()->json(['data' => DB::table('tr_dokumen_siklus_aset')->where('id', $existing->id)->first()], 200, ['Idempotent-Replayed' => 'true']);
        }
        try { $kode = $numbers->issue('management-aset.'.$type, $tenant, $type.':'.$key, (string) $data['legal_entity_id']); }
        catch (RuntimeException $e) { return response()->json(['error' => ['code' => 'number_sequence_unavailable', 'message' => $e->getMessage()]], 503); }
        $record = ['id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => $key, 'jenis_dokumen' => $type, 'kode' => $kode, 'legal_entity_id' => $data['legal_entity_id'], 'responsible_org_unit_id' => $data['responsible_org_unit_id'], 'asset_id' => $data['asset_id'] ?? null, 'tanggal' => $data['tanggal'], 'status' => $type === 'dekomisioning-aset' ? 'submitted' : 'draft', 'nilai' => $data['nilai'] ?? null, 'keterangan' => $data['keterangan'] ?? null, 'created_at' => now(), 'updated_at' => now()];
        DB::table('tr_dokumen_siklus_aset')->insert($record);
        if ($type === 'dekomisioning-aset') $this->submitWorkflow((object) $record, $tenant, $key, $workflow);
        $record = (array) DB::table('tr_dokumen_siklus_aset')->where('id', $record['id'])->first();
        return response()->json(['data' => $record], 201);
    }

    private function submitWorkflow(object $record, string $tenant, string $key, WorkflowClient $workflow): void
    {
        try {
            $workflowId = $workflow->submit($tenant, 'dekomisioning:'.$key, $record->id, $record->asset_id, []);
        } catch (RuntimeException $exception) {
            abort(503, $exception->getMessage());
        }
        DB::table('tr_dokumen_siklus_aset')->where('id', $record->id)->update(['workflow_instance_id' => $workflowId, 'updated_at' => now()]);
    }

    private function guard(Request $request, string $type, string $action): void { abort_unless(in_array($type, self::TYPES, true) && in_array('management-aset.'.$type.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403); }
    private function tenant(Request $request): string { return (string) $request->attributes->get('coreerp.tenant_id'); }
    private function typeFromRequest(Request $request): string { return basename((string) $request->path()); }
}
