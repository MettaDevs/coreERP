<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PemeliharaanAset;

use App\Platform\Modules\Contracts\RowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobType;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceJobTypeJenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetStatusLog;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\WorkOrderCreator;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;
use stdClass;

/**
 * Work order pemeliharaan aset.
 *
 * Header memikul dokumen dan jadwalnya; aset justru berada di baris pekerjaan, mengikuti
 * Dynamics 365 F&O, sehingga satu perintah kerja dapat mencakup beberapa aset. Perpindahan
 * status dan pengisian checklist tidak ditangani di sini melainkan di controller
 * pelaksanaan, supaya penyuntingan dokumen dan pengerjaan lapangan tidak berbagi jalur.
 *
 * Penyaringan tenant tidak lagi ditulis di sini: model module membawanya sendiri lewat
 * `BelongsToTenant`. Yang tersisa hanyalah tabel yang di-`join`, karena tabel yang di-join
 * tidak ikut tersaring scope dan harus membawa `tenant_id`-nya sendiri.
 */
class PemeliharaanAsetController extends Controller
{
    private const RESOURCE = 'pemeliharaan-aset';

    /**
     * Batas kunci idempotensi. Core menolak idempotency_key di atas 160 karakter dan kunci
     * yang dikirim ke sana diawali `pemeliharaan-aset:` (18 karakter), jadi batas app harus
     * 142 supaya permintaan yang sah tidak pernah berubah menjadi 503 di Core.
     */
    private const MAX_CREATION_KEY = 142;

    private const TABEL = 'aset_tr_pemeliharaan_aset';

    private const TABEL_BARIS = 'aset_tr_pemeliharaan_aset_details';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');

        $query = PemeliharaanAset::query();
        app(OrganizationScope::class)->query($query, $request, 'legal_entity_id', 'responsible_org_unit_id');

        return response()->json(['data' => $this->withLookups($query)
            ->orderByDesc(self::TABEL.'.created_at')
            ->get()]);
    }

    public function jobTypesForAset(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $asetId = $request->validate(['aset_id' => ['required', 'ulid']])['aset_id'];

        $asetQuery = Aset::query()->where('id', $asetId);
        app(OrganizationScope::class)->asetQuery($asetQuery, $request);
        $aset = $asetQuery->toBase()->first(['jenis_aset_id']);
        if (! $aset || $aset->jenis_aset_id === null) {
            return response()->json(['data' => []]);
        }

        $linkedJobTypes = MaintenanceJobTypeJenisAset::query()->distinct()->pluck('job_type_id');

        $data = MaintenanceJobType::query()
            ->join('aset_m_maintenance_job_type_jenis_aset as relasi', function ($join): void {
                $join->on('relasi.job_type_id', '=', 'aset_m_maintenance_job_type.id')
                    ->on('relasi.tenant_id', '=', 'aset_m_maintenance_job_type.tenant_id');
            })
            ->where([
                'aset_m_maintenance_job_type.aktif' => true,
                'relasi.jenis_aset_id' => $aset->jenis_aset_id,
            ])
            ->orderBy('aset_m_maintenance_job_type.kode')
            ->toBase()
            ->get(['aset_m_maintenance_job_type.id', 'aset_m_maintenance_job_type.kode', 'aset_m_maintenance_job_type.nama']);

        // Tenant yang belum mengisi relasi jenis aset tetap dapat membuat work order.
        // Begitu satu job type mulai dikonfigurasi, pilihan untuk job type tersebut
        // mengikuti relasi F&O dan hanya muncul untuk jenis aset yang sesuai.
        if ($linkedJobTypes->isEmpty()) {
            $data = MaintenanceJobType::query()
                ->where('aktif', true)
                ->orderBy('kode')
                ->toBase()
                ->get(['id', 'kode', 'nama']);
        } else {
            $data = $data->merge(
                MaintenanceJobType::query()
                    ->where('aktif', true)
                    ->whereNotIn('id', $linkedJobTypes)
                    ->orderBy('kode')
                    ->toBase()
                    ->get(['id', 'kode', 'nama'])
            )->sortBy('kode')->values();
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $workOrder = $this->workOrder($request, $id);
        $workOrder->details = $this->jobLines($id);
        $workOrder->status_log = PemeliharaanAsetStatusLog::query()
            ->where('pemeliharaan_aset_id', $id)
            ->orderBy('created_at')
            ->toBase()->get();

        return response()->json(['data' => $workOrder], 200, ['ETag' => RowVersion::etag((int) $workOrder->version)]);
    }

    public function store(Request $request, WorkOrderCreator $creator): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        if ($existing = $creator->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);

        // Pemeriksaan jangkauan, master, dan aset, penerbitan nomor, serta checklist bawaan ada di
        // `WorkOrderCreator`, jalur yang sama dengan work order dari usulan jadwal dan permintaan
        // pemeliharaan.
        try {
            $result = $creator->create($request, $data, $key);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }
        $workOrder = $result['record'];
        if ($result['replayed']) {
            return response()->json(['data' => $workOrder], 200, ['Idempotent-Replayed' => 'true']);
        }

        return response()->json(['data' => $workOrder], 201, [
            // Alamatnya diturunkan dari permintaan yang sedang dilayani, bukan ditulis tangan.
            // Sampai 10 September 2026 baris ini menunjuk `/api/v1/<resource>/<id>` — alamat
            // modul waktu ia masih app tersendiri, dan alamat yang tidak ada lagi sejak rutenya
            // pindah ke `/api/modules/management-aset/v1/`. Klien yang mengikuti `Location`
            // sesudah membuat record mendarat di 404, dan tidak ada yang gagal karenanya karena
            // tidak ada yang memeriksa isi header ini.
            //
            // `$request->url()` adalah alamat koleksi yang baru saja dikirimi POST, jadi ia
            // tidak dapat menyimpang dari awalan rutenya — termasuk bila awalannya berubah lagi.
            'Location' => $request->url().'/'.$workOrder->id,
        ]);
    }

    public function update(Request $request, string $id, WorkOrderCreator $creator): JsonResponse
    {
        $this->guard($request, 'update');
        $workOrder = $this->workOrder($request, $id);
        // Baris pekerjaan memikul hasil checklist, jam aktual, dan penugasan. Sesudah
        // dokumen dijadwalkan, mengganti seluruh baris berarti membuang pekerjaan yang
        // sudah dilakukan orang, jadi penggantian massal hanya sah selama masih draf.
        abort_unless(
            WorkOrderStatus::dapatDisunting($workOrder->status),
            422,
            'Hanya work order draf yang dapat diubah. Batalkan atau kembalikan ke draf lebih dahulu.',
        );

        $data = $this->validated($request);
        $version = RowVersion::expected($request);
        // Dua-duanya diperiksa: unit tempat dokumen berada sekarang dan unit tujuan
        // perubahan. Tanpa yang pertama, dokumen dapat dipindahkan keluar dari unit yang
        // tidak boleh disentuh pengguna; tanpa yang kedua, dipindahkan ke unit asing.
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $locations = $creator->validateLookups($request, $data);

        DB::transaction(function () use ($request, $id, $version, $data, $locations, $creator): void {
            RowVersion::claim(PemeliharaanAset::query()->whereKey($id), $version);
            PemeliharaanAset::query()->whereKey($id)->update([
                ...$creator->header($request, $data, '', '', false),
                'updated_at' => now(),
            ]);
            PemeliharaanAsetDetail::query()->where('pemeliharaan_aset_id', $id)->delete();
            $creator->replaceJobLines($id, $data['details'], $locations);
        });

        return $this->show($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $workOrder = $this->workOrder($request, $id);
        abort_unless(
            in_array($workOrder->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::DIBATALKAN], true),
            422,
            'Hanya work order draf atau yang sudah dibatalkan yang dapat diarsipkan.',
        );
        $version = RowVersion::expected($request);
        app(OrganizationScope::class)->require($request, $workOrder->legal_entity_id, $workOrder->responsible_org_unit_id);

        DB::transaction(function () use ($id, $version): void {
            RowVersion::claim(PemeliharaanAset::query()->whereKey($id), $version);
            PemeliharaanAset::query()->whereKey($id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'tipe_work_order_id' => ['required', 'ulid'],
            'tingkat_layanan_id' => ['nullable', 'ulid'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'penanggung_jawab_user_id' => ['nullable', 'string', 'max:64'],
            'diharapkan_mulai' => ['nullable', 'date'],
            'diharapkan_selesai' => ['nullable', 'date', 'after_or_equal:diharapkan_mulai'],
            'dijadwalkan_mulai' => ['nullable', 'date'],
            'dijadwalkan_selesai' => ['nullable', 'date', 'after_or_equal:dijadwalkan_mulai'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.aset_id' => ['required', 'ulid'],
            'details.*.maintenance_job_type_id' => ['required', 'ulid'],
            'details.*.variant_id' => ['nullable', 'ulid'],
            'details.*.trade_id' => ['nullable', 'ulid'],
            'details.*.ditugaskan_ke_user_id' => ['nullable', 'string', 'max:64'],
            'details.*.dijadwalkan_mulai' => ['nullable', 'date'],
            'details.*.dijadwalkan_selesai' => ['nullable', 'date', 'after_or_equal:details.*.dijadwalkan_mulai'],
            'details.*.estimasi_jam' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'details.*.catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        // Sebab kerusakan dan tindakan perbaikan sengaja tidak diterima di sini. Keduanya
        // adalah temuan, bukan rencana, dan diisi saat pekerjaan dikerjakan.

        return $data;
    }

    /**
     * Nama master ikut dibaca agar daftar tidak perlu satu permintaan tambahan per baris.
     *
     * Hasilnya sengaja baris mentah, bukan model: jawabannya memuat kolom gabungan dari
     * beberapa tabel yang tidak dimiliki model mana pun. Penyaringan tenant tetap datang
     * dari scope model, yang sudah diterapkan sebelum query diturunkan.
     *
     * @param  Builder<PemeliharaanAset>  $query
     */
    private function withLookups(Builder $query): QueryBuilder
    {
        return $query
            ->leftJoin('aset_m_tipe_work_order as tipe', function ($join): void {
                $join->on('tipe.id', '=', self::TABEL.'.tipe_work_order_id')->on('tipe.tenant_id', '=', self::TABEL.'.tenant_id');
            })
            ->leftJoin('aset_m_tingkat_layanan as layanan', function ($join): void {
                $join->on('layanan.id', '=', self::TABEL.'.tingkat_layanan_id')->on('layanan.tenant_id', '=', self::TABEL.'.tenant_id');
            })
            ->selectSub(
                PemeliharaanAsetDetail::query()
                    ->selectRaw('count(*)')
                    ->whereColumn(self::TABEL_BARIS.'.pemeliharaan_aset_id', self::TABEL.'.id')
                    ->toBase(),
                'jumlah_baris',
            )
            ->addSelect([
                self::TABEL.'.*',
                'tipe.kode as tipe_work_order_kode', 'tipe.nama as tipe_work_order_nama',
                'tipe.satu_pekerja',
                'layanan.kode as tingkat_layanan_kode', 'layanan.nama as tingkat_layanan_nama',
            ])
            ->toBase();
    }

    /** @return Collection<int, stdClass> */
    private function jobLines(string $workOrderId): Collection
    {
        return PemeliharaanAsetDetail::query()
            ->leftJoin('aset_tr_aset as aset', function ($join): void {
                $join->on('aset.id', '=', self::TABEL_BARIS.'.aset_id')->on('aset.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_maintenance_job_type as pekerjaan', function ($join): void {
                $join->on('pekerjaan.id', '=', self::TABEL_BARIS.'.maintenance_job_type_id')->on('pekerjaan.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_trade as keahlian', function ($join): void {
                $join->on('keahlian.id', '=', self::TABEL_BARIS.'.trade_id')->on('keahlian.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_sebab_kerusakan as sebab', function ($join): void {
                $join->on('sebab.id', '=', self::TABEL_BARIS.'.sebab_kerusakan_id')->on('sebab.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_tindakan_perbaikan as tindakan', function ($join): void {
                $join->on('tindakan.id', '=', self::TABEL_BARIS.'.tindakan_perbaikan_id')->on('tindakan.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->where(self::TABEL_BARIS.'.pemeliharaan_aset_id', $workOrderId)
            ->orderBy(self::TABEL_BARIS.'.line_number')
            ->toBase()
            ->get([
                self::TABEL_BARIS.'.*',
                'aset.kode as aset_kode',
                'pekerjaan.kode as job_type_kode', 'pekerjaan.nama as job_type_nama',
                'keahlian.nama as trade_nama',
                'sebab.nama as sebab_kerusakan_nama',
                'tindakan.nama as tindakan_perbaikan_nama',
            ]);
    }

    private function workOrder(Request $request, string $id): stdClass
    {
        // Setiap kolom di sini disebut lengkap dengan nama tabelnya, dan itu keharusan:
        // `withLookups()` di bawah menyambung dua tabel master yang sama-sama punya kolom
        // `id`, sehingga `where('id', ...)` polos ditolak PostgreSQL dengan "column
        // reference id is ambiguous" — sebagai 500, bukan sebagai hasil yang salah.
        //
        // Selama tabel utamanya masih beralias `wo`, penyebutan polos tidak pernah ambigu.
        // Aliasnya dibuang karena penyaringan tenant disisipkan scope dengan nama tabel yang
        // sebenarnya; konsekuensinya seluruh penyebutan kolom di jalur ini ikut berubah.
        $query = PemeliharaanAset::query()->where(self::TABEL.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::TABEL.'.legal_entity_id', self::TABEL.'.responsible_org_unit_id');

        return $this->withLookups($query)->firstOrFail();
    }

    private function creationKey(Request $request): string
    {
        return (string) validator(
            ['key' => $request->header('Idempotency-Key')],
            ['key' => ['required', 'string', 'max:'.self::MAX_CREATION_KEY, 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate()['key'];
    }

    private function guard(Request $request, string $action): void
    {
        abort_unless(
            in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true),
            403,
        );
    }
}
