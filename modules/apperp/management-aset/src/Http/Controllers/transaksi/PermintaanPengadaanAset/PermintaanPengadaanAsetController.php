<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PermintaanPengadaanAset;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use stdClass;

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
        $planLines = PerencanaanAsetDetail::query()->with('perencanaan')->whereIn('id', $record->details->pluck('planning_detail_id'))->get()->keyBy('id');
        $received = PenerimaanAsetDetail::query()
            ->whereIn('permintaan_pembelian_detail_id', $record->details->pluck('id'))
            ->whereHas('penerimaan', fn ($query) => $query->where('status', 'selesai'))
            ->selectRaw('permintaan_pembelian_detail_id, sum(jumlah) as quantity')
            ->groupBy('permintaan_pembelian_detail_id')->pluck('quantity', 'permintaan_pembelian_detail_id');
        foreach ($record->details as $line) {
            $plan = $planLines->get($line->planning_detail_id);
            $line->planning_code = $plan?->perencanaan?->kode;
            $line->unit = $plan?->unit;
            $line->received_quantity = $received->get($line->id, 0);
            $line->remaining_quantity = max(0, (float) $line->quantity - (float) $line->received_quantity);
        }

        return response()->json(['data' => $record], 200, ['ETag' => RowVersion::etag((int) $record->version)]);
    }

    public function store(Request $request, AssetNumberSequenceIssuer $numbers): JsonResponse
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
        DB::transaction(function () use ($request, $data, $key, $numbers, $tenant): void {
            $this->validatePlanning($request, $data);
            $kode = $numbers->issue('management-aset.permintaan-pembelian-aset', $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
            $record = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => $key, 'kode' => $kode, 'legal_entity_id' => $data['legal_entity_id'], 'requesting_org_unit_id' => $data['requesting_org_unit_id'], 'requester_user_id' => (string) $request->attributes->get('coreerp.user_id'), 'requested_on' => $data['requested_on'], 'status' => 'draft', 'description' => $data['description'] ?? null, 'created_at' => now(), 'updated_at' => now()];
            (new PermintaanPengadaanAset)->forceFill($record)->save();
            $this->replaceDetails($record['id'], $data['details']);
        });

        // Dibaca ulang supaya `version` yang dipulangkan adalah nilai di database.
        return response()->json(['data' => PermintaanPengadaanAset::query()->where('creation_key', $key)->toBase()->first()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $record = $this->record($request, $id);
        abort_unless($record->status === 'draft', 422, 'Hanya permintaan draf yang dapat diubah.');
        $data = $this->data($request);
        $version = RowVersion::expected($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['requesting_org_unit_id']);
        $this->validateTypes($data['details']);
        DB::transaction(function () use ($request, $id, $data, $version): void {
            RowVersion::claim(PermintaanPengadaanAset::query()->whereKey($id), $version);
            $this->assertNoReceipts($id);
            $this->validatePlanning($request, $data);
            $changed = PermintaanPengadaanAset::query()->where(['id' => $id, 'status' => 'draft'])->update(['legal_entity_id' => $data['legal_entity_id'], 'requesting_org_unit_id' => $data['requesting_org_unit_id'], 'requested_on' => $data['requested_on'], 'description' => $data['description'] ?? null, 'updated_at' => now()]);
            abort_unless($changed > 0, 422, 'Hanya permintaan draf yang dapat diubah.');
            PermintaanPengadaanAsetDetail::query()->where('request_id', $id)->delete();
            $this->replaceDetails($id, $data['details']);
        });

        return $this->show($request, $id);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'cancel');
        $record = $this->record($request, $id);
        abort_unless(in_array($record->status, ['draft', 'submitted'], true), 422, 'Permintaan ini tidak dapat dibatalkan.');
        $version = RowVersion::expected($request);
        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(PermintaanPengadaanAset::query()->whereKey($id), $version);
            $this->assertNoReceipts($id);
            $changed = PermintaanPengadaanAset::query()->where('id', $id)->whereIn('status', ['draft', 'submitted'])->update(['status' => 'cancelled', 'updated_at' => now()]);
            abort_unless($changed > 0, 422, 'Permintaan ini tidak dapat dibatalkan.');
        });

        return $this->show($request, $id);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $planningDetail = Rule::exists('aset_tr_perencanaan_aset_details', 'id')->where(function (QueryBuilder $query) use ($request): void {
            $tenantId = $this->tenant($request);
            $plans = DB::table('aset_tr_perencanaan_aset')->where('tenant_id', $tenantId)->whereNull('deleted_at');
            app(OrganizationScope::class)->query($plans, $request, 'legal_entity_id', 'planning_org_unit_id');
            $query->where('tenant_id', $tenantId)->whereIn('planning_id', $plans->select('id'));
        });

        return $request->validate(['legal_entity_id' => ['required', 'ulid'], 'requesting_org_unit_id' => ['required', 'ulid'], 'requested_on' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:2000'], 'details' => ['required', 'array', 'min:1'], 'details.*.planning_detail_id' => ['required', 'ulid', 'distinct', $planningDetail], 'details.*.jenis_aset_id' => ['required', 'ulid'], 'details.*.satuan_id' => ['required', 'ulid'], 'details.*.quantity' => ['required', 'numeric', 'gt:0'], 'details.*.specification' => ['required', 'string', 'max:2000'], 'details.*.note' => ['nullable', 'string', 'max:2000']], ['details.*.planning_detail_id.required' => 'Pilih baris perencanaan untuk setiap barang yang diminta.']);
    }

    /** @param array<string, mixed> $data */
    private function validatePlanning(Request $request, array $data): void
    {
        $lines = PerencanaanAsetDetail::query()->whereIn('id', array_column($data['details'], 'planning_detail_id'))->get()->keyBy('id');
        $plans = PerencanaanAset::query()->whereIn('id', $lines->pluck('planning_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = PerencanaanAsetDetail::query()->whereIn('id', array_column($data['details'], 'planning_detail_id'))->get()->keyBy('id');
        foreach (array_values($data['details']) as $index => $detail) {
            $line = $lines->get($detail['planning_detail_id']);
            $plan = $line === null ? null : $plans->get($line->planning_id);
            if ($plan === null || $plan->legal_entity_id !== $data['legal_entity_id']) {
                throw ValidationException::withMessages(['details.'.$index.'.planning_detail_id' => 'Pilih perencanaan milik perusahaan yang sama.']);
            }
            app(OrganizationScope::class)->require($request, $plan->legal_entity_id, $plan->planning_org_unit_id);
            if ($line->jenis_aset_id !== $detail['jenis_aset_id'] || $line->satuan_id !== $detail['satuan_id']) {
                throw ValidationException::withMessages(['details.'.$index.'.planning_detail_id' => 'Jenis aset dan satuan harus sesuai dengan baris perencanaan.']);
            }
        }
    }

    private function assertNoReceipts(string $id): void
    {
        $hasReceipts = PenerimaanAsetDetail::query()
            ->whereIn('permintaan_pembelian_detail_id', PermintaanPengadaanAsetDetail::query()->where('request_id', $id)->select('id'))
            ->whereHas('penerimaan')
            ->exists();
        abort_if($hasReceipts, 422, 'Permintaan sudah dipakai pada penerimaan dan tidak dapat diubah atau dibatalkan.');
    }

    /** @param list<array<string, mixed>> $details */
    private function replaceDetails(string $id, array $details): void
    {
        $names = JenisAset::query()->whereIn('id', array_column($details, 'jenis_aset_id'))->pluck('nama', 'id');
        collect($details)->values()->each(fn ($detail, $i) => PermintaanPengadaanAsetDetail::create(['request_id' => $id, 'line_number' => $i + 1, 'planning_detail_id' => $detail['planning_detail_id'] ?? null, 'jenis_aset_id' => $detail['jenis_aset_id'], 'satuan_id' => $detail['satuan_id'], 'nama_aset' => $names[$detail['jenis_aset_id']], 'quantity' => $detail['quantity'], 'specification' => $detail['specification'], 'note' => $detail['note'] ?? null]));
    }

    /** @param list<array<string, mixed>> $details */
    private function validateTypes(array $details): void
    {
        abort_unless(JenisAset::query()->whereIn('id', array_unique(array_column($details, 'jenis_aset_id')))->where('aktif', true)->count() === count(array_unique(array_column($details, 'jenis_aset_id'))), 422, 'Jenis aset tidak ditemukan atau tidak aktif.');
    }

    /** Baris mentah hasil `toBase()`: sebuah `stdClass`, bukan model. */
    private function record(Request $request, string $id): stdClass
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
