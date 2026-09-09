<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PermintaanPengadaanAset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Permintaan pengadaan aset.
 *
 * Penyaringan tenant datang dari scope model, jadi tidak ada lagi `where tenant_id` yang
 * ditulis tangan di sini.
 */
class PermintaanPengadaanAsetController extends Controller
{
    private const RESOURCE = 'permintaan-pembelian-aset';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $q = PermintaanPengadaanAset::query();
        app(OrganizationScope::class)->query($q, $request, 'legal_entity_id', 'requesting_org_unit_id');

        return response()->json(['data' => $q->toBase()->latest('requested_on')->get()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $record = $this->record($request, $id);
        $record->details = PermintaanPengadaanAsetDetail::query()->where('request_id', $id)->orderBy('line_number')->toBase()->get();

        return response()->json(['data' => $record]);
    }

    public function store(Request $request, PenerbitNomorAset $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->key($request);
        $tenant = $this->tenant($request);
        // `withTrashed`: replay idempoten harus tetap menemukan permintaan yang sudah
        // diarsipkan, kalau tidak permintaan ulang mencoba menyisipkan baris kembar.
        if ($existing = PermintaanPengadaanAset::withTrashed()->where('creation_key', $key)->toBase()->first()) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }
        $data = $this->data($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['requesting_org_unit_id']);
        $this->validateTypes($data['details']);
        $kode = $numbers->issue('management-aset.permintaan-pembelian-aset', $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        $record = DB::transaction(function () use ($request, $data, $key, $kode, $tenant): array {
            $record = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => $key, 'kode' => $kode, 'legal_entity_id' => $data['legal_entity_id'], 'requesting_org_unit_id' => $data['requesting_org_unit_id'], 'requester_user_id' => (string) $request->attributes->get('coreerp.user_id'), 'requested_on' => $data['requested_on'], 'status' => 'draft', 'description' => $data['description'] ?? null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
            (new PermintaanPengadaanAset)->forceFill($record)->save();
            $this->replaceDetails($record['id'], $data['details']);

            return $record;
        });

        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $record = $this->record($request, $id);
        abort_unless($record->status === 'draft', 422, 'Hanya permintaan draf yang dapat diubah.');
        $data = $this->data($request);
        $version = (int) $request->validate(['version' => ['required', 'integer']])['version'];
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['requesting_org_unit_id']);
        $this->validateTypes($data['details']);
        $changed = DB::transaction(function () use ($id, $data, $version) {
            $changed = PermintaanPengadaanAset::query()->where(['id' => $id, 'version' => $version, 'status' => 'draft'])->update(['legal_entity_id' => $data['legal_entity_id'], 'requesting_org_unit_id' => $data['requesting_org_unit_id'], 'requested_on' => $data['requested_on'], 'description' => $data['description'] ?? null, 'version' => $version + 1, 'updated_at' => now()]);
            if ($changed) {
                PermintaanPengadaanAsetDetail::query()->where('request_id', $id)->delete();
                $this->replaceDetails($id, $data['details']);
            }

            return $changed;
        });
        abort_unless($changed, 409, 'Permintaan telah berubah.');

        return $this->show($request, $id);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'cancel');
        $record = $this->record($request, $id);
        abort_unless(in_array($record->status, ['draft', 'submitted'], true), 422, 'Permintaan ini tidak dapat dibatalkan.');
        PermintaanPengadaanAset::query()->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);

        return $this->show($request, $id);
    }

    private function data(Request $request): array
    {
        return $request->validate(['legal_entity_id' => ['required', 'ulid'], 'requesting_org_unit_id' => ['required', 'ulid'], 'requested_on' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:2000'], 'details' => ['required', 'array', 'min:1'], 'details.*.planning_detail_id' => ['nullable', 'ulid'], 'details.*.jenis_aset_id' => ['required', 'ulid'], 'details.*.satuan_id' => ['required', 'ulid'], 'details.*.quantity' => ['required', 'numeric', 'gt:0'], 'details.*.specification' => ['required', 'string', 'max:2000'], 'details.*.note' => ['nullable', 'string', 'max:2000']]);
    }

    private function replaceDetails(string $id, array $details): void
    {
        $names = JenisAset::query()->whereIn('id', array_column($details, 'jenis_aset_id'))->pluck('nama', 'id');
        collect($details)->values()->each(fn ($detail, $i) => PermintaanPengadaanAsetDetail::create(['request_id' => $id, 'line_number' => $i + 1, 'planning_detail_id' => $detail['planning_detail_id'] ?? null, 'jenis_aset_id' => $detail['jenis_aset_id'], 'satuan_id' => $detail['satuan_id'], 'asset_name' => $names[$detail['jenis_aset_id']], 'quantity' => $detail['quantity'], 'specification' => $detail['specification'], 'note' => $detail['note'] ?? null]));
    }

    private function validateTypes(array $details): void
    {
        abort_unless(JenisAset::query()->whereIn('id', array_unique(array_column($details, 'jenis_aset_id')))->where('aktif', true)->count() === count(array_unique(array_column($details, 'jenis_aset_id'))), 422, 'Jenis aset tidak ditemukan atau tidak aktif.');
    }

    private function record(Request $request, string $id): object
    {
        $q = PermintaanPengadaanAset::query()->where('id', $id);
        app(OrganizationScope::class)->query($q, $request, 'legal_entity_id', 'requesting_org_unit_id');

        return $q->toBase()->firstOrFail();
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function key(Request $request): string
    {
        return (string) validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:154']])->validate()['key'];
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
