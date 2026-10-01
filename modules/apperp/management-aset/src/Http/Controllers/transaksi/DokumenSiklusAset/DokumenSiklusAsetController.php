<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\DokumenSiklusAset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Services\AssetApprovalWorkflow;
use Modules\Apperp\ManagementAset\Services\AssetNumberSequenceIssuer;
use Modules\Apperp\ManagementAset\Services\DisposalPosting;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use RuntimeException;
use stdClass;

class DokumenSiklusAsetController extends Controller
{
    /**
     * Penjualan dan pemusnahan lahir di sini sebagai draf dan tidak melepas apa pun. Asetnya baru dilepas,
     * bukunya ditutup, dan jurnal pelepasannya terbit saat draf diposting lewat `AssetDisposalController`,
     * seperti jurnal aset tetap Business Central.
     *
     * `pemeliharaan-aset` sengaja tidak lagi di sini. Ia dulu sebuah catatan satu baris
     * dan kini menjadi work order dengan baris pekerjaan, checklist, penugasan, dan
     * status pengerjaan sendiri; lihat `transaksi\PemeliharaanAset`. Baris lama pada
     * `aset_tr_dokumen_siklus_aset` tidak disentuh, hanya tidak lagi dilayani route ini.
     */
    public const TYPES = ['permintaan-pembelian-aset', 'dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'];

    public function indexByRoute(Request $request): JsonResponse
    {
        return $this->index($request, $this->typeFromRequest($request));
    }

    public function storeByRoute(Request $request, AssetNumberSequenceIssuer $numbers, AssetApprovalWorkflow $workflow): JsonResponse
    {
        return $this->store($request, $this->typeFromRequest($request), $numbers, $workflow);
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $this->guard($request, $type, 'read');
        $query = DokumenSiklusAset::query()->where('jenis_dokumen', $type);
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');

        $rows = $query->toBase()->latest('created_at')->get();
        // Kode dan nama aset di samping id-nya, dibaca sekaligus untuk seluruh daftar.
        $aset = Aset::withTrashed()->whereIn('id', $rows->pluck('aset_id')->filter()->unique()->values()->all())->toBase()->get(['id', 'kode', 'nama'])->keyBy('id');
        foreach ($rows as $row) {
            $row->aset_kode = $aset[$row->aset_id]->kode ?? null;
            $row->aset_nama = $aset[$row->aset_id]->nama ?? null;
        }

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, string $type, AssetNumberSequenceIssuer $numbers, AssetApprovalWorkflow $workflow): JsonResponse
    {
        $this->guard($request, $type, 'create');
        $key = (string) $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:154', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'], 'responsible_org_unit_id' => ['required', 'ulid'], 'tanggal' => ['required', 'date'],
            'aset_id' => ['nullable', 'ulid', Rule::exists('aset_tr_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'nilai' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($type === DisposalPosting::SCRAP && ! empty($data['nilai'])) {
            throw ValidationException::withMessages(['nilai' => DisposalPosting::SCRAP_HAS_NO_PROCEEDS]);
        }
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        if (in_array($type, ['dekomisioning-aset', 'penjualan-aset', 'pemusnahan-aset'], true)) {
            validator($data, ['aset_id' => ['required']])->validate();
        }
        if ($data['aset_id'] ?? null) {
            $aset = app(OrganizationScope::class)->asetQuery(
                Aset::query()->where('id', $data['aset_id']),
                $request,
            )->toBase()->first();
            abort_unless($aset, 404);
            abort_unless($aset->legal_entity_id === $data['legal_entity_id'] && $aset->responsible_org_unit_id === $data['responsible_org_unit_id'], 422, 'Entitas dan unit kerja dokumen harus sama dengan aset.');
            if ($type === 'dekomisioning-aset') {
                abort_unless(StatusAset::bolehDidekomisioning($aset->lifecycle_state), 422, 'Aset ini sudah tidak aktif atau sudah dilepas.');
            }
            if (in_array($type, ['penjualan-aset', 'pemusnahan-aset'], true)) {
                abort_unless(StatusAset::bolehDilepas($aset->lifecycle_state), 422, 'Aset harus disetujui untuk dekomisioning sebelum dijual atau dimusnahkan.');
            }
        }
        $existing = DokumenSiklusAset::query()->where('creation_key', $key)->toBase()->first();
        if ($existing) {
            // Perbaikan untuk dokumen yang dibuat **sebelum** pengajuan berada di dalam
            // transaksi. Waktu itu penyimpanan bisa berhasil sementara pengajuannya gagal,
            // dan dokumen tertinggal berstatus `submitted` tanpa instance mana pun. Dokumen
            // baru tidak bisa lagi berada pada keadaan itu; barisnya dipertahankan karena
            // dokumen lama masih ada di database pelanggan.
            if ($type === 'dekomisioning-aset' && $existing->workflow_instance_id === null) {
                $this->submitWorkflow($existing, $tenant, (string) $existing->legal_entity_id, $key, $workflow);
            }

            return response()->json(['data' => $this->dokumen((string) $existing->id)], 200, ['Idempotent-Replayed' => 'true']);
        }
        $record = ['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'creation_key' => $key, 'jenis_dokumen' => $type, 'legal_entity_id' => $data['legal_entity_id'], 'responsible_org_unit_id' => $data['responsible_org_unit_id'], 'aset_id' => $data['aset_id'] ?? null, 'tanggal' => $data['tanggal'], 'status' => $type === 'dekomisioning-aset' ? 'submitted' : 'draft', 'nilai' => $data['nilai'] ?? null, 'keterangan' => $data['keterangan'] ?? null, 'created_at' => now(), 'updated_at' => now()];
        try {
            // Nomor, dokumen, dan pengajuan persetujuan pada satu transaksi.
            //
            // Ketiganya dulu berdiri sendiri-sendiri karena dua di antaranya berjalan lewat
            // HTTP dan tidak mungkin ikut transaksi. Akibatnya dua keadaan setengah jadi yang
            // harus dijelaskan ke pemeriksa: nomor yang terbit untuk dokumen yang tidak jadi
            // ada, dan dokumen dekomisioning yang menunggu persetujuan yang tidak pernah
            // diajukan. Keduanya tidak mungkin lagi terjadi setelah Core berada di proses yang
            // sama — dan itu alasan terkuat seluruh pemindahan ini ada.
            $record = DB::transaction(function () use ($record, $type, $tenant, $key, $numbers, $workflow, $data): array {
                $record['kode'] = $numbers->issue('management-aset.'.$type, $tenant, $type.':'.$key, (string) $data['legal_entity_id']);
                (new DokumenSiklusAset)->forceFill($record)->save();
                if ($type === 'dekomisioning-aset') {
                    $this->submitWorkflow((object) $record, $tenant, (string) $data['legal_entity_id'], $key, $workflow);
                }

                return $record;
            });
        } catch (NumberSequenceException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        return response()->json(['data' => $this->dokumen((string) $record['id'])], 201);
    }

    /**
     * Dokumen apa adanya dari database, supaya jawaban memuat kolom hasil pengajuan.
     *
     * @return array<string, mixed>
     */
    private function dokumen(string $id): array
    {
        return (array) DokumenSiklusAset::query()->where('id', $id)->toBase()->first();
    }

    /**
     * `$record` adalah baris mentah hasil `toBase()` — sebuah `stdClass`, bukan model —
     * baik yang baru dirakit di sini maupun yang dibaca ulang saat replay idempoten.
     */
    private function submitWorkflow(stdClass $record, string $tenant, string $legalEntityId, string $key, AssetApprovalWorkflow $workflow): void
    {
        try {
            $workflowId = $workflow->submitDecommissioning($tenant, $legalEntityId, $key, (string) $record->id, (string) $record->aset_id);
        } catch (RuntimeException $exception) {
            // 422, bukan 503. Core berada di proses yang sama, jadi "layanan persetujuan belum
            // dapat dihubungi" tidak pernah lagi benar. Yang tersisa adalah permintaan yang
            // memang belum bisa dipenuhi — paling sering karena tenant ini belum menyalakan
            // alur persetujuan dekomisioning untuk entitas legalnya.
            abort(422, $exception->getMessage());
        }
        DokumenSiklusAset::query()->where('id', $record->id)->update(['workflow_instance_id' => $workflowId, 'updated_at' => now()]);
    }

    private function guard(Request $request, string $type, string $action): void
    {
        abort_unless(in_array($type, self::TYPES, true) && in_array('management-aset.'.$type.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    private function typeFromRequest(Request $request): string
    {
        return basename((string) $request->path());
    }
}
