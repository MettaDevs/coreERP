<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PenerimaanAset;

use App\Support\Modules\Contracts\DaftarVendor;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PresisiMataUang;
use App\Support\Modules\Contracts\SetelanPostingFinance;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Services\AcquisitionPosting;
use Modules\Apperp\ManagementAset\Services\AcquisitionPostingFailed;
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\OpeningBalance;
use Modules\Apperp\ManagementAset\Services\OpeningBalanceImport;
use Modules\Apperp\ManagementAset\Services\PembuatAset;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PenerimaanStatus;
use Modules\Apperp\ManagementAset\Support\ValidasiAtributAset;
use RuntimeException;
use stdClass;

/**
 * Dokumen penerimaan aset — satu kedatangan barang, banyak aset.
 *
 * **Masalah yang diselesaikannya.** Dua puluh kursi yang datang dengan satu surat jalan
 * adalah dua puluh aset: masing-masing punya kode yang tertempel di barangnya, dipelihara
 * sendiri, dimutasi sendiri, dan dilepas sendiri. Tetapi mengetiknya dua puluh kali bukan
 * hanya melelahkan — tidak ada satu pun berkas yang mengikat kedua puluhnya menjadi satu
 * penerimaan, padahal yang diperiksa pemeriksa justru surat jalannya.
 *
 * **Kenapa nilainya per unit.** Ambang kapitalisasi dibandingkan terhadap nilai satu
 * aset, sama seperti di F&O. Dua puluh kursi lima ratus ribu tetap dua puluh aset lima
 * ratus ribu; ia tidak melewati ambang sepuluh juta hanya karena datang bersamaan. Layar
 * diberi tahu lebih dulu lewat `ringkasan()` agar keputusan itu diketahui sebelum dua
 * puluh nomor aset terlanjur terbit.
 *
 * **Kenapa kodenya tetap dari number sequence.** Kode aset adalah kunci alami yang
 * dipakai seumur hidup aset. Menurunkannya dari nomor dokumen — `PNR-0007-01` dan
 * seterusnya — membuat kunci itu bergantung pada dokumen yang masih dapat dikoreksi.
 * Kodenya karena itu diambil berurutan dari `management-aset.aset`, persis seperti aset
 * yang diterima satuan.
 */
class PenerimaanAsetController extends Controller
{
    private const RESOURCE = 'penerimaan-aset';

    /** 160 batas Core dikurangi panjang awalan `penerimaan-aset:`. */
    private const MAX_CREATION_KEY = 143;

    private const TABEL = 'aset_tr_penerimaan_aset';

    private const TABEL_BARIS = 'aset_tr_penerimaan_aset_details';

    /**
     * Batas jumlah per baris.
     *
     * Bukan batas domain — tidak ada yang melarang menerima seribu kursi — melainkan
     * batas kewarasan satu permintaan: setiap unit menerbitkan satu nomor dan menulis
     * satu aset, satu penempatan, dan beberapa buku. Angka yang kelewat besar lebih
     * sering salah ketik daripada sungguhan, dan salah ketik yang lolos berarti seribu
     * nomor aset yang tidak dapat ditarik kembali.
     */
    private const MAX_JUMLAH_BARIS = 500;

    /** Batas kewarasan periode berjalan saldo awal: seratus tahun periode bulanan. */
    private const MAX_PERIODE_BERJALAN = 1200;

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PenerimaanStatus::semua())],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $query = PenerimaanAset::query();
        app(OrganizationScope::class)->query($query, $request, self::TABEL.'.legal_entity_id', self::TABEL.'.responsible_org_unit_id');
        if ($filter['status'] ?? null) {
            $query->where(self::TABEL.'.status', $filter['status']);
        }
        if ($filter['dari'] ?? null) {
            $query->whereDate(self::TABEL.'.tanggal', '>=', $filter['dari']);
        }
        if ($filter['sampai'] ?? null) {
            $query->whereDate(self::TABEL.'.tanggal', '<=', $filter['sampai']);
        }

        $tenant = $this->tenant($request);

        return response()->json(['data' => $this->withLookups($query)
            ->orderByDesc(self::TABEL.'.tanggal')
            ->orderByDesc(self::TABEL.'.created_at')
            ->get()
            ->map(fn (stdClass $baris): stdClass => $this->denganNama($tenant, $baris))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $penerimaan = $this->dokumen($request, $id);
        $penerimaan->details = $this->baris($id);
        $tenant = $this->tenant($request);
        // Vendor milik Core: nomor dan namanya dibaca ulang, bukan disalin ke dokumen (K-06).
        $vendor = $penerimaan->vendor_id === null ? null : app(DaftarVendor::class)->satu($tenant, (string) $penerimaan->vendor_id);
        $penerimaan->vendor = $vendor === null ? null : ['id' => $vendor['id'], 'number' => $vendor['number'], 'name' => $vendor['name'], 'status' => $vendor['status']];
        // Keadaan jurnalnya di feed posting finance, sesudah diselesaikan: perolehan, atau saldo awal.
        $penerimaan->posting = $penerimaan->status === PenerimaanStatus::SELESAI
            ? app(PenerbitPosting::class)->status($tenant, AcquisitionPosting::postingId($id, (string) $penerimaan->cara_perolehan))
            : null;

        return response()->json(['data' => $penerimaan]);
    }

    public function store(Request $request, PenerbitNomorAset $numbers): JsonResponse
    {
        $this->guard($request, 'create');
        $key = $this->creationKey($request);
        $tenant = $this->tenant($request);
        if ($existing = $this->replay($key)) {
            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $data = $this->validated($request);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $this->validateLookups($request, $data);
        $this->validateVendor($request, $data);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak
        // membakar counter, dan sebelum transaksi supaya kegagalan Core tidak menahan
        // koneksi database — urutan yang sama seperti mutasi dan work order.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $id = DB::transaction(function () use ($request, $data, $key, $kode): string {
                $record = $this->header($request, $data, $key, $kode);
                (new PenerimaanAset)->forceFill($record)->save();
                $this->gantiBaris((string) $record['id'], $data['details']);

                return (string) $record['id'];
            });
        } catch (QueryException $exception) {
            $existing = $this->replay($key);
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['data' => $existing], 200, ['Idempotent-Replayed' => 'true']);
        }

        $penerimaan = $this->dokumen($request, $id);
        $penerimaan->details = $this->baris($id);

        return response()->json(['data' => $penerimaan], 201, ['Location' => $request->url().'/'.$id]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $penerimaan = $this->dokumen($request, $id);
        abort_unless(
            PenerimaanStatus::dapatDisunting($penerimaan->status),
            422,
            'Penerimaan yang sudah selesai tidak dapat diubah; asetnya sudah terdaftar dan bernomor.',
        );

        $data = $this->validated($request);
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        // Dua jangkauan diperiksa: unit tempat dokumen berada sekarang dan unit tujuan
        // perubahan. Tanpa yang pertama, dokumen dapat dipindahkan keluar dari unit yang
        // tidak boleh disentuh pengguna; tanpa yang kedua, dipindahkan ke unit asing.
        app(OrganizationScope::class)->require($request, $penerimaan->legal_entity_id, $penerimaan->responsible_org_unit_id);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        $this->validateLookups($request, $data);
        $this->validateVendor($request, $data);

        $changed = DB::transaction(function () use ($request, $id, $version, $data): int {
            $updated = PenerimaanAset::query()
                ->where(['id' => $id, 'version' => $version, 'status' => PenerimaanStatus::DRAFT])
                ->update([
                    ...$this->header($request, $data, '', '', false),
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);
            if ($updated) {
                PenerimaanAsetDetail::query()->where('penerimaan_aset_id', $id)->delete();
                $this->gantiBaris($id, $data['details']);
            }

            return $updated;
        });
        if (! $changed) {
            return $this->staleVersion();
        }

        return $this->show($request, $id);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'archive');
        $penerimaan = $this->dokumen($request, $id);
        abort_unless(
            PenerimaanStatus::dapatDisunting($penerimaan->status),
            422,
            'Penerimaan yang sudah selesai tidak dapat diarsipkan; aset sudah terbentuk karenanya.',
        );
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        app(OrganizationScope::class)->require($request, $penerimaan->legal_entity_id, $penerimaan->responsible_org_unit_id);

        $updated = PenerimaanAset::query()
            ->where(['id' => $id, 'version' => $version, 'status' => PenerimaanStatus::DRAFT])
            ->update(['deleted_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);

        return $updated ? response()->json(status: 204) : $this->staleVersion();
    }

    /**
     * Ringkasan draf: berapa aset yang akan lahir, dan mana yang tidak akan menyusut.
     *
     * Dipisahkan dari `show()` karena ia menjawab pertanyaan yang berbeda — bukan "apa
     * isi dokumen ini" melainkan "apa yang terjadi bila saya menyelesaikannya". Ambang
     * kapitalisasi adalah keputusan yang jauh lebih murah diketahui sekarang daripada
     * setelah dua puluh nomor aset terbit dan tidak pernah menyusut sepeser pun.
     */
    public function ringkasan(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $this->dokumen($request, $id);
        $pembuat = app(PembuatAset::class);

        $baris = PenerimaanAsetDetail::query()
            ->where('penerimaan_aset_id', $id)
            ->orderBy('line_number')
            ->toBase()
            ->get(['line_number', 'nama', 'jumlah', 'nilai_per_unit', 'group_aset_id']);

        $peringatan = [];
        foreach ($baris as $row) {
            $ambang = $pembuat->ambangKapitalisasi((string) $row->group_aset_id);
            if ($ambang !== null && (float) $row->nilai_per_unit < (float) $ambang) {
                $peringatan[] = [
                    'line_number' => (int) $row->line_number,
                    'nama' => $row->nama,
                    'ambang_kapitalisasi' => $ambang,
                    'pesan' => 'Nilai per unit di bawah ambang kapitalisasi group. Tiap unit tetap tercatat sebagai aset, tetapi tidak akan disusutkan.',
                ];
            }
        }

        return response()->json(['data' => [
            'jumlah_baris' => $baris->count(),
            'jumlah_aset' => (int) $baris->sum('jumlah'),
            'peringatan' => $peringatan,
        ]]);
    }

    /**
     * Pratinjau jurnal perolehan sebelum penerimaan diselesaikan (TODO 9.3.2, K-22): baris jurnal
     * yang akan terbit, dimensinya, masalah pemetaannya beserta jalan pintas perbaikannya, dan hal
     * yang akan menolak penyelesaian. Pemeriksaannya sama persis dengan penerbitan, tanpa menyimpan
     * apa pun, jadi yang ditampilkan di sini adalah yang akan terbit.
     */
    public function pratinjauPosting(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $penerimaan = $this->dokumen($request, $id);
        abort_unless(
            $penerimaan->status === PenerimaanStatus::DRAFT,
            422,
            'Penerimaan ini sudah diselesaikan; jurnalnya sudah terbit dan keadaannya ada di rincian dokumen.',
        );
        $lines = PenerimaanAsetDetail::query()->where('penerimaan_aset_id', $id)->orderBy('line_number')->toBase()->get();

        try {
            $hasil = $lines->isEmpty()
                ? ['blockers' => ['details' => 'Tambahkan baris barang lebih dulu.'], 'posting' => null]
                : app(AcquisitionPosting::class)->preview($penerimaan, $lines);
        } catch (AcquisitionPostingFailed $kegagalan) {
            return response()->json(['error' => ['code' => 'posting_failed', 'message' => $kegagalan->getMessage()]], 500);
        }

        $payload = $hasil['posting']['payload'] ?? null;

        return response()->json(['data' => [
            'blockers' => array_map(
                static fn (string $field, string $message): array => ['field' => $field, 'message' => $message],
                array_keys($hasil['blockers']),
                array_values($hasil['blockers']),
            ),
            'status' => $hasil['posting']['status'] ?? null,
            'settlement_mode' => $payload['settlement_mode'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'lines' => array_map(static fn (array $baris): array => [
                'line_no' => (int) $baris['line_no'],
                'account_code' => $baris['account']['code'] ?? null,
                'account_name' => $baris['account']['name'] ?? null,
                'description' => $baris['description'] ?? null,
                'debit' => (string) $baris['debit'],
                'credit' => (string) $baris['credit'],
                'dimensions' => array_map(static fn (array $dimensi): array => [
                    'code' => (string) $dimensi['code'],
                    'display_name' => $dimensi['display_name'] ?? null,
                    'value_code' => $dimensi['value_code'] ?? null,
                    'value_display_name' => $dimensi['value_display_name'] ?? null,
                ], $baris['financial_dimensions'] ?? []),
            ], $payload['journal_lines'] ?? []),
            'problems' => $hasil['posting']['problems'] ?? [],
        ]]);
    }

    /**
     * Impor saldo awal aset lama dari CSV (TODO 10.6): berkas menjadi draf penerimaan saldo awal, satu
     * per tanggal perolehan, tanggal siap pakai, dan lokasi. Tanpa `apply` hasilnya pratinjau; dengan
     * `apply` draf benar-benar dibuat, semuanya atau tidak sama sekali, seperti impor daftar akun Core.
     *
     * Setiap draf melewati aturan yang sama persis dengan layar penerimaan — termasuk cutover dan angka
     * per buku — dan kesalahannya dilaporkan per nomor baris berkas. Draf yang lahir tetap draf:
     * pratinjau jurnal dan penyelesaiannya dilakukan per dokumen, sama seperti yang diketik di layar.
     * Percobaan ulang dengan `Idempotency-Key` yang sama memulangkan draf yang sudah dibuat.
     */
    public function imporSaldoAwal(Request $request, PenerbitNomorAset $numbers, OpeningBalanceImport $impor): JsonResponse
    {
        $this->guard($request, 'create');
        $form = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt'],
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'receiving_org_unit_id' => ['nullable', 'ulid'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'apply' => ['sometimes', 'boolean'],
        ]);
        app(OrganizationScope::class)->require($request, $form['legal_entity_id'], $form['responsible_org_unit_id']);
        $apply = filter_var($form['apply'] ?? false, FILTER_VALIDATE_BOOL);
        $awalanKunci = $apply ? substr($this->creationKey($request), 0, self::MAX_CREATION_KEY - 13).':impor:' : null;
        if ($awalanKunci !== null && ($sudah = $this->drafImpor($awalanKunci)) !== []) {
            return response()->json(['data' => ['status' => 'applied', 'rows' => null, 'receipts' => $sudah, 'rejected' => []]], 200, ['Idempotent-Replayed' => 'true']);
        }
        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $berkas = $impor->read((string) $file->get());
        $ditolak = $berkas['rejected'];
        $draf = [];
        foreach ($berkas['receipts'] as $receipt) {
            try {
                $data = $this->validated($request, [
                    'legal_entity_id' => $form['legal_entity_id'],
                    'responsible_org_unit_id' => $form['responsible_org_unit_id'],
                    'receiving_org_unit_id' => $form['receiving_org_unit_id'] ?? null,
                    'tanggal' => $receipt['header']['tanggal'],
                    'tanggal_siap_pakai' => $receipt['header']['tanggal_siap_pakai'],
                    'lokasi_aset_id' => $receipt['header']['lokasi_aset_id'],
                    'currency_code' => $form['currency_code'] ?? 'IDR',
                    'cara_perolehan' => AcquisitionMethod::OPENING_BALANCE,
                    'details' => $receipt['details'],
                ]);
                $this->validateLookups($request, $data);
                $draf[] = ['data' => $data, 'receipt' => $receipt];
            } catch (ValidationException $kegagalan) {
                foreach ($kegagalan->errors() as $kunci => $pesan) {
                    // `details.3.nilai_per_unit` menunjuk baris keempat draf itu; kesalahan kepala
                    // dokumen (misalnya tanggal sesudah cutover) dilaporkan pada baris pertamanya.
                    $baris = preg_match('/^details\.(\d+)\.(.+)$/', $kunci, $cocok) === 1 ? (int) $cocok[1] : 0;
                    $ditolak[] = ['line' => $receipt['lines'][$baris] ?? $receipt['lines'][0], 'field' => $cocok[2] ?? $kunci, 'reason' => (string) $pesan[0]];
                    unset($cocok);
                }
            }
        }
        usort($ditolak, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $ringkasan = array_map(fn (array $satu): array => $this->ringkasanImpor($this->tenant($request), $satu['receipt'], $satu['data']), $draf);

        if (! $apply || $ditolak !== [] || $draf === []) {
            $status = ! $apply ? 'preview' : 'rejected';

            return response()->json(['data' => ['status' => $status, 'rows' => $berkas['rows'], 'receipts' => $ringkasan, 'rejected' => $ditolak]]);
        }

        // Nomor diterbitkan lebih dulu, di luar transaksi, seperti `store()`; draf-drafnya lalu
        // disimpan di satu transaksi, sehingga berkas yang gagal di tengah tidak meninggalkan separuh.
        $tenant = $this->tenant($request);
        try {
            foreach ($draf as $urut => $satu) {
                $draf[$urut]['key'] = $awalanKunci.($urut + 1);
                $draf[$urut]['kode'] = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$draf[$urut]['key'], $form['legal_entity_id']);
            }
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }
        try {
            DB::transaction(function () use ($request, $draf): void {
                foreach ($draf as $satu) {
                    $record = $this->header($request, $satu['data'], $satu['key'], $satu['kode']);
                    (new PenerimaanAset)->forceFill($record)->save();
                    $this->gantiBaris((string) $record['id'], $satu['data']['details']);
                }
            });
        } catch (QueryException $exception) {
            if ($this->drafImpor((string) $awalanKunci) === []) {
                throw $exception;
            }
        }

        return response()->json(['data' => ['status' => 'applied', 'rows' => $berkas['rows'], 'receipts' => $this->drafImpor((string) $awalanKunci), 'rejected' => []]], 201);
    }

    /** Baris judul templat impor saldo awal, untuk diisi di spreadsheet (TODO 10.6). */
    public function templatSaldoAwal(Request $request): HttpResponse
    {
        $this->guard($request, 'create');

        return response(implode(',', OpeningBalanceImport::HEADER)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="templat-saldo-awal-aset.csv"',
        ]);
    }

    /**
     * Draf yang sudah lahir dari satu impor, dikenali dari awalan kunci penciptaannya.
     *
     * @return list<array<string, mixed>>
     */
    private function drafImpor(string $awalanKunci): array
    {
        return PenerimaanAset::query()
            ->where('creation_key', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $awalanKunci).'%')
            ->orderBy('kode')
            ->toBase()
            ->get(['id', 'kode', 'tanggal', 'tanggal_siap_pakai', 'status'])
            ->map(static fn (stdClass $baris): array => [
                'id' => (string) $baris->id,
                'kode' => (string) $baris->kode,
                'tanggal' => substr((string) $baris->tanggal, 0, 10),
                'tanggal_siap_pakai' => $baris->tanggal_siap_pakai === null ? null : substr((string) $baris->tanggal_siap_pakai, 0, 10),
                'status' => (string) $baris->status,
            ])
            ->all();
    }

    /**
     * Ringkasan satu draf impor untuk pratinjau: baris berkas yang membentuknya, jumlah aset, dan nilai
     * yang akan tercatat.
     *
     * @param  array{header: array{tanggal: string, tanggal_siap_pakai: ?string, lokasi: ?string}, lines: list<int>}  $receipt
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ringkasanImpor(string $tenant, array $receipt, array $data): array
    {
        $nilai = BigDecimal::zero();
        $akumulasi = BigDecimal::zero();
        $aset = 0;
        foreach ($data['details'] as $detail) {
            $jumlah = (int) $detail['jumlah'];
            $aset += $jumlah;
            $nilai = $nilai->plus(app(PresisiMataUang::class)->bulatkan($tenant, (string) BigDecimal::of((string) $detail['nilai_per_unit'])->multipliedBy($jumlah), (string) $data['currency_code']));
            $akumulasi = $akumulasi->plus(BigDecimal::of((string) $detail['akumulasi_per_unit'])->multipliedBy($jumlah));
        }

        return [
            'tanggal' => $receipt['header']['tanggal'],
            'tanggal_siap_pakai' => $receipt['header']['tanggal_siap_pakai'],
            'lokasi' => $receipt['header']['lokasi'],
            'lines' => $receipt['lines'],
            'jumlah_aset' => $aset,
            'nilai' => (string) $nilai,
            'akumulasi' => (string) $akumulasi,
        ];
    }

    /**
     * Buku yang akan lahir untuk aset satu group, dengan buku yang di-post ke finance lebih dulu, untuk
     * isian saldo awal per buku (TODO 10.1.1, K-28). Dijaga izin membaca penerimaan, bukan izin group
     * aset: yang mencatat saldo awal tidak harus boleh mengubah master.
     */
    public function buku(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $query = $request->validate([
            'group_aset_id' => ['required', 'ulid', Rule::exists('aset_m_group_aset', 'id')->where('tenant_id', $this->tenant($request))],
        ]);

        return response()->json(['data' => app(PembuatAset::class)->bukuGroup((string) $query['group_aset_id'])]);
    }

    /**
     * Vendor aktif satu entitas legal untuk pemilih vendor penerimaan. Vendor milik Core (K-06) dan
     * boleh dilihat semua anggota tenant, jadi yang dijaga hanya izin membaca penerimaan.
     */
    public function vendor(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $query = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => app(DaftarVendor::class)->aktif($this->tenant($request), $query['legal_entity_id'], (string) ($query['q'] ?? ''))]);
    }

    /**
     * Menyelesaikan penerimaan: asetnya benar-benar terdaftar.
     *
     * Izinnya `management-aset.aset.create`, bukan izin dokumen ini. Menyusun berkas
     * penerimaan dan benar-benar menambah aset ke register adalah dua wewenang berbeda —
     * pemisahan yang sama yang sudah dipakai mutasi.
     *
     * Seluruh nomor aset diterbitkan lebih dulu, di luar transaksi, dengan kunci
     * idempoten yang diturunkan dari id dokumen dan nomor urut. Percobaan kedua setelah
     * jaringan putus karena itu memulangkan nomor yang sama, bukan deret baru.
     *
     * **Jurnal perolehannya terbit di transaksi yang sama** (feed posting finance, TODO 9.4):
     * penerimaan yang gagal tidak meninggalkan posting, dan penerimaan yang selesai pasti punya
     * posting. Yang menahan penyelesaian diperiksa sebelum nomor terbit — vendor wajib pada
     * pembelian `direct_payable`, dan group yang tidak punya buku yang di-post ke finance, seperti
     * D365 yang menghentikan posting faktur tanpa buku ber-lapisan Current. Pemetaan akun yang
     * kosong tidak menahan: postingnya tertahan di Core, penerimaannya tetap selesai (K-18).
     */
    public function selesaikan(Request $request, string $id, PenerbitNomorAset $numbers, PembuatAset $pembuat): JsonResponse
    {
        $this->guardAset($request, 'create');
        $penerimaan = $this->dokumen($request, $id);
        abort_unless($penerimaan->status === PenerimaanStatus::DRAFT, 422, 'Penerimaan ini sudah diselesaikan.');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        app(OrganizationScope::class)->require($request, $penerimaan->legal_entity_id, $penerimaan->responsible_org_unit_id);

        $lines = PenerimaanAsetDetail::query()->where('penerimaan_aset_id', $id)->orderBy('line_number')->toBase()->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['details' => 'Penerimaan tanpa baris barang tidak dapat diselesaikan.']);
        }
        $this->assertSisaPermintaan($lines, $id);
        $perolehan = app(AcquisitionPosting::class);
        $penghalang = $perolehan->blockers($penerimaan, $lines);
        if ($penghalang !== []) {
            throw ValidationException::withMessages($penghalang);
        }

        $tenant = $this->tenant($request);
        // Kunci penciptaan disusun lebih dulu supaya penerbitan nomor dan penyimpanan aset
        // memakai kunci yang sama persis; itulah yang membuat percobaan ulang memulangkan
        // aset yang sudah ada alih-alih membuat kembarannya.
        //
        // Nilai tiap aset adalah bagian dari nilai baris yang sudah dibulatkan (K-20), supaya
        // jumlah register sama persis dengan jurnal perolehannya.
        $kunci = [];
        foreach ($lines as $line) {
            $nilai = $perolehan->lineAmounts($tenant, (string) $penerimaan->currency_code, $line);
            for ($urut = 1; $urut <= (int) $line->jumlah; $urut++) {
                $kunci[] = [
                    'line' => $line,
                    'key' => 'penerimaan:'.$id.':'.$line->line_number.':'.$urut,
                    'value' => $nilai['unit_values'][$urut - 1],
                    'tax' => $nilai['unit_taxes'][$urut - 1],
                    'accumulated' => $nilai['unit_accumulated'][$urut - 1],
                ];
            }
        }
        // Saldo awal (TODO 10.3): buku aset lahir dengan akumulasinya, dan penyusutannya berlanjut
        // mulai cutover. Cutover pasti ada di sini; tanpanya `blockers()` sudah menolak.
        $cutover = $penerimaan->cara_perolehan === AcquisitionMethod::OPENING_BALANCE ? $perolehan->cutover($penerimaan) : null;

        try {
            foreach ($kunci as $i => $satuan) {
                $kunci[$i]['kode'] = $numbers->issue('management-aset.aset', $tenant, 'aset:'.$satuan['key'], $penerimaan->legal_entity_id);
            }
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $changed = DB::transaction(function () use ($penerimaan, $id, $version, $kunci, $tenant, $pembuat, $perolehan, $lines, $cutover): int {
                // Status dipindahkan lebih dahulu dan dengan `version` sebagai syarat. Dua
                // penyelesaian yang berlomba akan melahirkan dua kali lipat aset, dan tidak
                // ada cara mengetahui mana yang berlebih. Yang kalah menemukan nol baris
                // terpengaruh dan berhenti di sini.
                $updated = PenerimaanAset::query()
                    ->where(['id' => $id, 'version' => $version, 'status' => PenerimaanStatus::DRAFT])
                    ->update(['status' => PenerimaanStatus::SELESAI, 'version' => $version + 1, 'updated_at' => now()]);
                if (! $updated) {
                    return 0;
                }

                $terbit = [];
                foreach ($kunci as $satuan) {
                    $aset = $pembuat->buat($tenant, $satuan['key'], $satuan['kode'], $this->spesifikasiAset($penerimaan, $satuan['line'], $satuan['value'], $cutover));
                    $terbit[] = [
                        'asset_code' => (string) $aset->kode,
                        'group_aset_id' => (string) $satuan['line']->group_aset_id,
                        'acquisition_value' => $satuan['value'],
                        'tax_amount' => $satuan['tax'],
                        'accumulated_depreciation' => $satuan['accumulated'],
                    ];
                }
                $perolehan->publish($penerimaan, $lines, $terbit);

                return $updated;
            });
        } catch (AcquisitionPostingFailed $kegagalan) {
            return response()->json(['error' => ['code' => 'posting_failed', 'message' => $kegagalan->getMessage()]], 500);
        }
        if (! $changed) {
            return $this->staleVersion();
        }

        return $this->show($request, $id);
    }

    /**
     * Aset yang lahir dari dokumen ini, untuk layar pengisian nomor seri.
     *
     * Nomor seri sengaja tidak diminta saat penerimaan: kardusnya belum dibuka. Yang
     * dibutuhkan sesudahnya adalah daftar dua puluh aset itu saja — bukan seluruh
     * register — supaya nomor seri dapat diketik berurutan sambil membaca stikernya.
     */
    public function asetTerbit(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'read');
        $this->dokumen($request, $id);

        $daftar = Aset::query()
            ->where('penerimaan_aset_id', $id)
            ->orderBy('kode')
            ->toBase()
            ->get(['id', 'kode', 'nama', 'serial_number', 'penerimaan_aset_detail_id']);

        return response()->json(['data' => $daftar]);
    }

    /**
     * Mengisi nomor seri sekaligus untuk aset yang lahir dari dokumen ini.
     *
     * Satu permintaan, bukan dua puluh, karena orang yang mengetiknya sedang memegang dua
     * puluh stiker dan membacanya berurutan. Mengirim satu per satu membuat kegagalan di
     * tengah meninggalkan separuh terisi tanpa ada yang tahu separuh mana.
     *
     * Izinnya `management-aset.aset.update`: yang diubah adalah aset, bukan dokumennya.
     * Dokumen yang sudah selesai memang tidak dapat disunting, dan ini bukan pengecualian
     * terhadapnya — nomor seri tidak pernah menjadi bagian dokumen.
     */
    public function isiNomorSeri(Request $request, string $id): JsonResponse
    {
        $this->guardAset($request, 'update');
        $this->dokumen($request, $id);

        $data = $request->validate([
            'serial' => ['required', 'array', 'min:1'],
            'serial.*.aset_id' => ['required', 'ulid'],
            'serial.*.serial_number' => ['nullable', 'string', 'max:150'],
        ]);

        $milikDokumen = Aset::query()
            ->where('penerimaan_aset_id', $id)
            ->toBase()->pluck('id')->all();

        $diminta = array_column(array_values($data['serial']), 'aset_id');
        if (array_diff($diminta, $milikDokumen) !== []) {
            throw ValidationException::withMessages([
                'serial' => 'Ada aset yang bukan berasal dari penerimaan ini.',
            ]);
        }

        DB::transaction(function () use ($data): void {
            foreach (array_values($data['serial']) as $baris) {
                Aset::query()->where('id', $baris['aset_id'])->update([
                    'serial_number' => ($baris['serial_number'] ?? '') === '' ? null : $baris['serial_number'],
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->asetTerbit($request, $id);
    }

    /**
     * Sisa permintaan pembelian dihitung, bukan disimpan.
     *
     * Kolom sisa akan menjadi salah pada kegagalan pertama yang membuat aset lahir tetapi
     * kolomnya tidak sempat berkurang. Menghitungnya dari jumlah aset yang benar-benar
     * ada membuat jawabannya selalu sama dengan kenyataan.
     *
     * @param  Collection<int, stdClass>  $lines
     */
    private function assertSisaPermintaan(Collection $lines, string $penerimaanId): void
    {
        foreach ($lines as $line) {
            if (! $line->permintaan_pembelian_detail_id) {
                continue;
            }
            // Lewat model, bukan query builder mentah: yang mentah melewati global scope
            // tenant, dan baris permintaan pembelian milik tenant lain akan terbaca tanpa
            // ada yang memberi tahu. Penjaganya `TenantScopeBoundaryTest`.
            $diminta = (float) PermintaanPengadaanAsetDetail::query()
                ->where('id', $line->permintaan_pembelian_detail_id)
                ->toBase()
                ->value('quantity');
            // Aset milik dokumen ini belum ada saat pemeriksaan berjalan, jadi yang
            // dihitung adalah penerimaan sebelumnya; baris ini ditambahkan sendiri.
            $sudah = Aset::query()
                ->whereIn('penerimaan_aset_detail_id', PenerimaanAsetDetail::query()
                    ->where('permintaan_pembelian_detail_id', $line->permintaan_pembelian_detail_id)
                    ->where('penerimaan_aset_id', '!=', $penerimaanId)
                    ->toBase()->pluck('id')->all())
                ->toBase()->count();
            if ($sudah + (int) $line->jumlah > $diminta) {
                $sisa = max(0, (int) $diminta - $sudah);
                throw ValidationException::withMessages([
                    'details' => 'Baris "'.$line->nama.'" melebihi permintaan pembelian. Sisa yang belum diterima tinggal '.$sisa.'.',
                ]);
            }
        }
    }

    /**
     * Bentuk payload yang dimengerti `PembuatAset`, disusun dari header dan baris.
     *
     * Header memasok yang berlaku untuk seluruh kedatangan — tanggal, lokasi, unit,
     * mata uang — dan baris memasok yang membedakan barangnya.
     *
     * `$cutover` hanya ada pada saldo awal: buku asetnya lahir dengan akumulasi dan periode
     * berjalan dari baris ini, dan penyusutannya tidak mulai sebelum cutover (TODO 10.3).
     *
     * @return array<string, mixed>
     */
    private function spesifikasiAset(stdClass $penerimaan, stdClass $line, string $nilai, ?string $cutover = null): array
    {
        return [
            'nama' => $line->nama,
            'legal_entity_id' => $penerimaan->legal_entity_id,
            'usage_org_unit_id' => $penerimaan->responsible_org_unit_id,
            'receiving_org_unit_id' => $penerimaan->receiving_org_unit_id,
            'received_by_user_id' => $penerimaan->diterima_oleh_user_id,
            'custodian_user_id' => $penerimaan->penanggung_jawab_user_id,
            'group_aset_id' => $line->group_aset_id,
            'jenis_aset_id' => $line->jenis_aset_id,
            'kondisi_aset_id' => $line->kondisi_aset_id,
            'pabrikan_aset_id' => $line->pabrikan_aset_id,
            'model_aset_id' => $line->model_aset_id,
            'model_number' => $line->model_number,
            'lokasi_aset_id' => $penerimaan->lokasi_aset_id,
            // Nomor seri dibiarkan kosong: kardusnya belum dibuka saat dokumen dibuat.
            'serial_number' => null,
            'acquired_on' => substr((string) $penerimaan->tanggal, 0, 10),
            'placed_in_service_on' => $penerimaan->tanggal_siap_pakai === null
                ? null
                : substr((string) $penerimaan->tanggal_siap_pakai, 0, 10),
            'acquisition_value' => $nilai,
            'residual_value' => $line->residu_per_unit,
            'currency_code' => $penerimaan->currency_code,
            'keterangan' => $line->keterangan,
            'atribut' => $this->atributBaris($line),
            'penerimaan_aset_id' => $penerimaan->id,
            'penerimaan_aset_detail_id' => $line->id,
            'opening_balance' => $cutover === null ? null : ['starts_on' => $cutover, 'amounts' => OpeningBalance::fromLine($line)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function atributBaris(stdClass $line): array
    {
        $nilai = $line->atribut;
        if (is_string($nilai)) {
            $nilai = json_decode($nilai, true);
        }

        return is_array($nilai) ? array_values($nilai) : [];
    }

    /**
     * `$input` diisi impor saldo awal: draf yang tersusun dari berkas melewati aturan yang sama persis
     * dengan layar, dan bukan isi permintaannya sendiri (TODO 10.6).
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?array $input = null): array
    {
        $tenant = $this->tenant($request);
        $milikTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenant)->whereNull('deleted_at');

        $data = validator($input ?? $request->all(), [
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'receiving_org_unit_id' => ['nullable', 'ulid'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'tanggal_siap_pakai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tanggal'],
            'diterima_oleh_user_id' => ['nullable', 'string', 'max:64'],
            'penanggung_jawab_user_id' => ['nullable', 'string', 'max:64'],
            'lokasi_aset_id' => ['nullable', 'ulid', $milikTenant('aset_m_lokasi_aset')],
            'currency_code' => ['required', 'string', 'size:3'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'cara_perolehan' => ['sometimes', Rule::in(AcquisitionMethod::ALL)],
            'vendor_id' => ['nullable', 'ulid'],
            'vendor_invoice_reference' => ['nullable', 'string', 'max:80'],
            'vendor_invoice_date' => ['nullable', 'date_format:Y-m-d'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.nama' => ['required', 'string', 'max:150'],
            'details.*.group_aset_id' => ['required', 'ulid', $milikTenant('aset_m_group_aset')],
            'details.*.jenis_aset_id' => ['required', 'ulid', $milikTenant('aset_m_jenis_aset')],
            'details.*.kondisi_aset_id' => ['nullable', 'ulid', $milikTenant('aset_m_kondisi_aset')],
            'details.*.pabrikan_aset_id' => ['nullable', 'ulid', $milikTenant('aset_m_pabrikan_aset')],
            'details.*.model_aset_id' => ['nullable', 'ulid', $milikTenant('aset_m_model_aset')],
            'details.*.model_number' => ['nullable', 'string', 'max:150'],
            'details.*.jumlah' => ['required', 'integer', 'min:1', 'max:'.self::MAX_JUMLAH_BARIS],
            'details.*.nilai_per_unit' => ['required', 'numeric', 'min:0'],
            'details.*.ppn_per_unit' => ['nullable', 'numeric', 'min:0'],
            'details.*.residu_per_unit' => ['nullable', 'numeric', 'min:0'],
            'details.*.akumulasi_per_unit' => ['nullable', 'numeric', 'min:0'],
            'details.*.periode_berjalan' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PERIODE_BERJALAN],
            'details.*.saldo_awal_buku' => ['sometimes', 'nullable', 'array'],
            'details.*.saldo_awal_buku.*.buku_id' => ['required', 'ulid'],
            'details.*.saldo_awal_buku.*.akumulasi_per_unit' => ['required', 'numeric', 'min:0'],
            'details.*.saldo_awal_buku.*.periode_berjalan' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PERIODE_BERJALAN],
            'details.*.permintaan_pembelian_detail_id' => ['nullable', 'ulid', Rule::exists('aset_tr_permintaan_pengadaan_aset_details', 'id')->where('tenant_id', $tenant)],
            'details.*.keterangan' => ['nullable', 'string', 'max:2000'],
            'details.*.atribut' => ['sometimes', 'array'],
            'details.*.atribut.*.tipe_atribut_id' => ['required', 'ulid'],
            'details.*.atribut.*.nilai' => ['present'],
        ], [
            'cara_perolehan.in' => 'Pilih pembelian, hibah, atau saldo awal.',
        ])->validate();

        // `details` dijadikan list di sini, bukan dipercayai sudah berupa list.
        // `['required', 'array']` meloloskan objek JSON berkunci teks — `{"a": {...}}`
        // adalah array yang sah bagi Laravel — dan kunci itu terbawa sampai ke penomoran
        // baris, tempat `$index + 1` berhenti sebagai TypeError, bukan sebagai pesan
        // validasi.
        $data['details'] = array_values($data['details']);
        $data['cara_perolehan'] ??= AcquisitionMethod::PURCHASE;

        // Harga satuan dan PPN per unit boleh memakai presisi harga satuan mata uangnya (K-20),
        // tidak lebih halus. Keduanya disimpan sebagai teks desimal, bukan float, supaya yang
        // dikalikan saat jurnal disusun adalah angka yang diketik.
        $mataUang = strtoupper((string) $data['currency_code']);
        try {
            $desimal = app(PresisiMataUang::class)->hargaSatuan($this->tenant($request), $mataUang);
        } catch (RuntimeException $kegagalan) {
            throw ValidationException::withMessages(['currency_code' => $kegagalan->getMessage()]);
        }
        $pesan = [];
        foreach ($data['details'] as $indeks => $detail) {
            foreach (['nilai_per_unit', 'ppn_per_unit'] as $kolom) {
                $angka = self::angka($detail[$kolom] ?? 0);
                $data['details'][$indeks][$kolom] = $angka;
                $koma = strpos($angka, '.');
                if ($koma !== false && strlen($angka) - $koma - 1 > $desimal) {
                    $pesan['details.'.$indeks.'.'.$kolom] = sprintf('Paling banyak %d angka di belakang koma untuk %s.', $desimal, $mataUang);
                }
            }
        }
        if ($pesan !== []) {
            throw ValidationException::withMessages($pesan);
        }
        $this->validasiSaldoAwal($request, $data);

        return $data;
    }

    /**
     * Aturan saldo awal (TODO 10.1, K-27, K-28), sekaligus memastikan dokumen lain tidak membawa angka
     * saldo awal.
     *
     * Akumulasi diketik berpresisi nilai mata uang, jadi ia tidak pernah dibulatkan, dan bersama
     * residunya tidak boleh melebihi nilai per unit. Periode berjalan tidak boleh melebihi masa
     * manfaat buku yang memakainya: angka baris berlaku untuk buku yang di-post dan setiap buku yang
     * tidak diisi tersendiri, jadi keduanya diperiksa terhadap masa manfaat masing-masing buku.
     * Tanggal sesudah cutover sudah ditolak di sini bila cutover-nya diketahui; bila belum, penolakannya
     * menunggu saat diselesaikan.
     *
     * @param  array<string, mixed>  $data
     */
    private function validasiSaldoAwal(Request $request, array &$data): void
    {
        $saldoAwal = $data['cara_perolehan'] === AcquisitionMethod::OPENING_BALANCE;
        $pesan = [];
        if ($saldoAwal) {
            foreach (['vendor_id', 'vendor_invoice_reference', 'vendor_invoice_date'] as $kolom) {
                if (($data[$kolom] ?? null) !== null && $data[$kolom] !== '') {
                    $pesan[$kolom] = 'Saldo awal tidak punya vendor maupun faktur; kosongkan isian ini.';
                }
            }
            $cutover = app(SetelanPostingFinance::class)->cutover((string) $data['legal_entity_id']);
            $masalah = $cutover === null ? null : AcquisitionPosting::masalahCutover((string) $data['tanggal'], $cutover);
            if ($masalah !== null) {
                $pesan['tanggal'] = $masalah;
            }
        }

        $desimal = app(PresisiMataUang::class)->nilai($this->tenant($request), strtoupper((string) $data['currency_code']));
        $bukuGroup = [];
        foreach ($data['details'] as $indeks => $detail) {
            $kunci = 'details.'.$indeks.'.';
            $akumulasi = self::angka($detail['akumulasi_per_unit'] ?? 0);
            $periode = (int) ($detail['periode_berjalan'] ?? 0);
            $isian = array_values($detail['saldo_awal_buku'] ?? []);
            $data['details'][$indeks]['akumulasi_per_unit'] = $akumulasi;
            $data['details'][$indeks]['periode_berjalan'] = $periode;
            $data['details'][$indeks]['saldo_awal_buku'] = null;
            if (! $saldoAwal) {
                if (BigDecimal::of($akumulasi)->isPositive() || $periode > 0 || $isian !== []) {
                    $pesan[$kunci.'akumulasi_per_unit'] = 'Akumulasi dan periode berjalan hanya diisi untuk saldo awal.';
                }

                continue;
            }
            if (BigDecimal::of((string) $detail['ppn_per_unit'])->isPositive()) {
                $pesan[$kunci.'ppn_per_unit'] = 'PPN tidak berlaku untuk saldo awal.';
            }

            $groupId = (string) $detail['group_aset_id'];
            $bukuGroup[$groupId] ??= array_column(app(PembuatAset::class)->bukuGroup($groupId), null, 'buku_id');
            $diPost = array_values(array_filter($bukuGroup[$groupId], static fn (array $buku): bool => $buku['di_post']))[0]['buku_id'] ?? null;
            $nilai = BigDecimal::of((string) $detail['nilai_per_unit']);
            $residu = BigDecimal::of(self::angka($detail['residu_per_unit'] ?? 0));

            // Angka per buku: yang diisi tersendiri, lalu angka baris untuk sisanya.
            $perBuku = [];
            foreach ($isian as $urut => $buku) {
                $kunciBuku = $kunci.'saldo_awal_buku.'.$urut.'.';
                $bukuId = (string) $buku['buku_id'];
                if (! isset($bukuGroup[$groupId][$bukuId])) {
                    $pesan[$kunciBuku.'buku_id'] = 'Buku ini tidak ada di matriks group x buku group baris ini.';

                    continue;
                }
                if ($bukuId === $diPost || isset($perBuku[$bukuId])) {
                    $pesan[$kunciBuku.'buku_id'] = 'Angka buku yang di-post ke finance diisi di baris itu sendiri, dan tiap buku lain cukup diisi sekali.';

                    continue;
                }
                $perBuku[$bukuId] = ['kunci' => $kunciBuku, 'akumulasi' => self::angka($buku['akumulasi_per_unit']), 'periode' => (int) $buku['periode_berjalan']];
            }

            foreach ([['kunci' => $kunci, 'akumulasi' => $akumulasi], ...array_values($perBuku)] as $angka) {
                $koma = strpos($angka['akumulasi'], '.');
                if ($koma !== false && strlen($angka['akumulasi']) - $koma - 1 > $desimal) {
                    $pesan[$angka['kunci'].'akumulasi_per_unit'] = sprintf('Akumulasi paling banyak %d angka di belakang koma.', $desimal);
                } elseif (BigDecimal::of($angka['akumulasi'])->plus($residu)->isGreaterThan($nilai)) {
                    $pesan[$angka['kunci'].'akumulasi_per_unit'] = 'Akumulasi ditambah nilai residu tidak boleh melebihi nilai per unit.';
                }
            }
            foreach ($bukuGroup[$groupId] as $bukuId => $buku) {
                $angka = $perBuku[$bukuId] ?? ['kunci' => $kunci, 'periode' => $periode];
                if ($buku['masa_manfaat'] !== null && $angka['periode'] > $buku['masa_manfaat']) {
                    $pesan[$angka['kunci'].'periode_berjalan'] = sprintf(
                        'Periode berjalan melebihi masa manfaat buku %s (%d periode).%s',
                        $buku['kode'],
                        $buku['masa_manfaat'],
                        isset($perBuku[$bukuId]) || $bukuId === $diPost ? '' : ' Isi angka buku itu tersendiri.',
                    );
                }
            }

            $data['details'][$indeks]['saldo_awal_buku'] = $perBuku === [] ? null : array_values(array_map(
                static fn (string $bukuId, array $angka): array => ['buku_id' => $bukuId, 'akumulasi_per_unit' => $angka['akumulasi'], 'periode_berjalan' => $angka['periode']],
                array_keys($perBuku),
                $perBuku,
            ));
        }
        if ($pesan !== []) {
            throw ValidationException::withMessages($pesan);
        }
    }

    /**
     * Vendor harus milik Core dan milik entitas legal dokumen ini (K-06). Vendor yang sudah
     * nonaktif tetap boleh tinggal di dokumen lama, tetapi tidak boleh dipilih baru.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateVendor(Request $request, array $data): void
    {
        if (($data['vendor_id'] ?? null) === null) {
            return;
        }
        $vendor = app(DaftarVendor::class)->satu($this->tenant($request), (string) $data['vendor_id']);
        if ($vendor === null || $vendor['legal_entity_id'] !== $data['legal_entity_id']) {
            throw ValidationException::withMessages(['vendor_id' => 'Pilih vendor milik entitas legal penerimaan ini.']);
        }
    }

    /**
     * Angka dari masukan sebagai teks desimal tanpa nol di ekor. JSON mengirim angka sebagai float;
     * mengubahnya dengan `(string)` biasa bisa memotong digit, jadi float ditulis dengan sepuluh
     * desimal lalu dirapikan.
     */
    private static function angka(mixed $nilai): string
    {
        $teks = match (true) {
            is_string($nilai) => trim($nilai),
            is_int($nilai) => (string) $nilai,
            is_float($nilai) => sprintf('%.10F', $nilai),
            default => '0',
        };

        return str_contains($teks, '.') ? rtrim(rtrim($teks, '0'), '.') : $teks;
    }

    /**
     * Kombinasi pabrikan x model x jenis pada tiap baris harus sah, dengan aturan yang
     * sama persis seperti pada layar register — F&O pun tidak membedakan keduanya.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateLookups(Request $request, array $data): void
    {
        $tenant = $this->tenant($request);

        foreach ($data['details'] as $index => $detail) {
            // Nilai atribut diperiksa sudah di sini, bukan menunggu penyelesaian.
            // Atribut diwarisi jenis aset, jadi salahnya sudah dapat diketahui saat draf
            // diketik — dan draf yang lolos padahal mustahil diselesaikan adalah jebakan
            // yang baru terbuka setelah orangnya menekan tombol terakhir.
            try {
                app(ValidasiAtributAset::class)->rowsFor(
                    $tenant,
                    (string) $detail['jenis_aset_id'],
                    $detail['atribut'] ?? [],
                );
            } catch (ValidationException $kegagalan) {
                throw ValidationException::withMessages(
                    // Kunci pesannya dipindahkan ke barisnya supaya layar menyorot baris
                    // yang benar; tanpa ini seluruh pesan menumpuk di satu tempat.
                    collect($kegagalan->errors())
                        ->mapWithKeys(fn (array $pesan, string $kunci): array => [
                            'details.'.$index.'.'.$kunci => $pesan,
                        ])
                        ->all(),
                );
            }

            $modelId = $detail['model_aset_id'] ?? null;
            if (! $modelId) {
                continue;
            }
            $model = ModelAset::query()
                ->where('id', $modelId)
                ->toBase()
                ->first(['pabrikan_aset_id', 'jenis_aset_id', 'aktif']);
            if (! $model) {
                continue;
            }
            $baris = 'details.'.$index.'.model_aset_id';
            if (! $model->aktif) {
                throw ValidationException::withMessages([$baris => 'Model yang dipilih sudah tidak aktif.']);
            }
            if (($detail['pabrikan_aset_id'] ?? null) !== $model->pabrikan_aset_id) {
                throw ValidationException::withMessages([$baris => 'Model harus berasal dari pabrikan yang dipilih.']);
            }
            $adaModelTerkait = ModelAset::query()
                ->where(['jenis_aset_id' => $detail['jenis_aset_id'], 'aktif' => true])
                ->exists();
            if ($adaModelTerkait && $model->jenis_aset_id !== $detail['jenis_aset_id']) {
                throw ValidationException::withMessages([$baris => 'Model ini belum dikaitkan dengan jenis aset yang dipilih.']);
            }
            if (! $adaModelTerkait && $model->jenis_aset_id !== null) {
                throw ValidationException::withMessages([$baris => 'Model ini hanya dapat dipakai pada jenis aset yang sudah dikaitkan dengannya.']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(Request $request, array $data, string $key, string $kode, bool $new = true): array
    {
        return array_filter([
            'id' => $new ? (string) Str::ulid() : null,
            'tenant_id' => $new ? $this->tenant($request) : null,
            'creation_key' => $new ? $key : null,
            'kode' => $new ? $kode : null,
            'legal_entity_id' => $data['legal_entity_id'],
            'responsible_org_unit_id' => $data['responsible_org_unit_id'],
            'tanggal' => $data['tanggal'],
            'currency_code' => strtoupper((string) $data['currency_code']),
            'cara_perolehan' => $data['cara_perolehan'],
            'status' => $new ? PenerimaanStatus::DRAFT : null,
            'version' => $new ? 1 : null,
            'created_at' => $new ? now() : null,
            'updated_at' => now(),
        ], static fn ($value) => $value !== null) + [
            // Di luar `array_filter` karena semuanya sah bernilai null, dan menyaringnya
            // membuat pengosongan lewat `PATCH` diam-diam tidak tersimpan.
            'receiving_org_unit_id' => $data['receiving_org_unit_id'] ?? null,
            'tanggal_siap_pakai' => $data['tanggal_siap_pakai'] ?? null,
            'diterima_oleh_user_id' => $data['diterima_oleh_user_id'] ?? null,
            'penanggung_jawab_user_id' => $data['penanggung_jawab_user_id'] ?? null,
            'lokasi_aset_id' => $data['lokasi_aset_id'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'vendor_invoice_reference' => $data['vendor_invoice_reference'] ?? null,
            'vendor_invoice_date' => $data['vendor_invoice_date'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $details */
    private function gantiBaris(string $penerimaanId, array $details): void
    {
        foreach ($details as $index => $detail) {
            PenerimaanAsetDetail::create([
                'penerimaan_aset_id' => $penerimaanId,
                'line_number' => $index + 1,
                'nama' => $detail['nama'],
                'group_aset_id' => $detail['group_aset_id'],
                'jenis_aset_id' => $detail['jenis_aset_id'],
                'kondisi_aset_id' => $detail['kondisi_aset_id'] ?? null,
                'pabrikan_aset_id' => $detail['pabrikan_aset_id'] ?? null,
                'model_aset_id' => $detail['model_aset_id'] ?? null,
                'model_number' => $detail['model_number'] ?? null,
                'jumlah' => $detail['jumlah'],
                'nilai_per_unit' => $detail['nilai_per_unit'],
                'ppn_per_unit' => $detail['ppn_per_unit'] ?? '0',
                'residu_per_unit' => $detail['residu_per_unit'] ?? 0,
                'akumulasi_per_unit' => $detail['akumulasi_per_unit'] ?? '0',
                'periode_berjalan' => $detail['periode_berjalan'] ?? 0,
                'saldo_awal_buku' => $detail['saldo_awal_buku'] ?? null,
                'permintaan_pembelian_detail_id' => $detail['permintaan_pembelian_detail_id'] ?? null,
                'atribut' => array_values($detail['atribut'] ?? []),
                'keterangan' => $detail['keterangan'] ?? null,
            ]);
        }
    }

    private function replay(string $key): ?stdClass
    {
        return PenerimaanAset::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    private function dokumen(Request $request, string $id): stdClass
    {
        $query = PenerimaanAset::query()->where(self::TABEL.'.id', $id);
        app(OrganizationScope::class)->query($query, $request, self::TABEL.'.legal_entity_id', self::TABEL.'.responsible_org_unit_id');

        return $this->denganNama($this->tenant($request), $this->withLookups($query)->firstOrFail());
    }

    /**
     * Melengkapi satu dokumen dengan nama unit kerja dan nama orang.
     *
     * Id tetap dipulangkan apa adanya — ia yang dikirim balik saat menyimpan — dan nama
     * ditambahkan di sebelahnya. Nama yang `null` berarti unit atau keanggotaannya sudah
     * tidak ada di Core; layar menampilkan idnya sebagai jalan terakhir, bukan kosong,
     * supaya dokumen lama tetap dapat ditelusuri.
     */
    private function denganNama(string $tenantId, stdClass $baris): stdClass
    {
        $direktori = app(DirektoriAset::class);
        $baris->responsible_org_unit_nama = $direktori->namaUnit($tenantId, $baris->responsible_org_unit_id ?? null);
        $baris->receiving_org_unit_nama = $direktori->namaUnit($tenantId, $baris->receiving_org_unit_id ?? null);
        $baris->diterima_oleh_nama = $direktori->namaOrang($tenantId, $baris->diterima_oleh_user_id ?? null);
        $baris->penanggung_jawab_nama = $direktori->namaOrang($tenantId, $baris->penanggung_jawab_user_id ?? null);

        return $baris;
    }

    /**
     * Nama lokasi, jumlah baris, dan jumlah aset ikut dibaca agar daftar tidak perlu satu
     * permintaan tambahan per dokumen.
     *
     * @param  Builder<PenerimaanAset>  $query
     */
    private function withLookups(Builder $query): QueryBuilder
    {
        return $query
            ->leftJoin('aset_m_lokasi_aset as lokasi', function ($join): void {
                $join->on('lokasi.id', '=', self::TABEL.'.lokasi_aset_id')
                    ->on('lokasi.tenant_id', '=', self::TABEL.'.tenant_id');
            })
            ->selectSub(
                PenerimaanAsetDetail::query()
                    ->selectRaw('count(*)')
                    ->whereColumn(self::TABEL_BARIS.'.penerimaan_aset_id', self::TABEL.'.id')
                    ->toBase(),
                'jumlah_baris',
            )
            ->selectSub(
                PenerimaanAsetDetail::query()
                    ->selectRaw('coalesce(sum(jumlah), 0)')
                    ->whereColumn(self::TABEL_BARIS.'.penerimaan_aset_id', self::TABEL.'.id')
                    ->toBase(),
                'jumlah_aset',
            )
            // `addSelect`, bukan daftar kolom pada `get()`. `selectSub` di atas sudah
            // mengisi daftar kolom query, dan Laravel mengabaikan argumen `get()` begitu
            // daftar itu tidak lagi null — hasilnya kolom yang diminta hilang tanpa satu
            // pun kesalahan, hanya field yang diam-diam bernilai null.
            ->addSelect([
                self::TABEL.'.*',
                'lokasi.kode as lokasi_aset_kode',
                'lokasi.nama as lokasi_aset_nama',
            ])
            ->toBase();
    }

    /**
     * Baris beserta nama master yang dirujuknya.
     *
     * @return Collection<int, stdClass>
     */
    private function baris(string $penerimaanId): Collection
    {
        return PenerimaanAsetDetail::query()
            ->leftJoin('aset_m_group_aset as grup', function ($join): void {
                $join->on('grup.id', '=', self::TABEL_BARIS.'.group_aset_id')
                    ->on('grup.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_jenis_aset as jenis', function ($join): void {
                $join->on('jenis.id', '=', self::TABEL_BARIS.'.jenis_aset_id')
                    ->on('jenis.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_kondisi_aset as kondisi', function ($join): void {
                $join->on('kondisi.id', '=', self::TABEL_BARIS.'.kondisi_aset_id')
                    ->on('kondisi.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_pabrikan_aset as pabrikan', function ($join): void {
                $join->on('pabrikan.id', '=', self::TABEL_BARIS.'.pabrikan_aset_id')
                    ->on('pabrikan.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_model_aset as model', function ($join): void {
                $join->on('model.id', '=', self::TABEL_BARIS.'.model_aset_id')
                    ->on('model.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->addSelect([
                self::TABEL_BARIS.'.*',
                'grup.nama as group_aset_nama',
                'jenis.nama as jenis_aset_nama',
                'kondisi.nama as kondisi_aset_nama',
                'pabrikan.nama as pabrikan_aset_nama',
                'model.nama as model_aset_nama',
            ])
            ->where(self::TABEL_BARIS.'.penerimaan_aset_id', $penerimaanId)
            ->orderBy(self::TABEL_BARIS.'.line_number')
            ->toBase()
            ->get();
    }

    private function staleVersion(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'stale_version',
            'message' => 'Penerimaan telah berubah. Muat ulang lalu coba lagi.',
        ]], 409);
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
        $this->requirePermission($request, 'management-aset.'.self::RESOURCE.'.'.$action);
    }

    private function guardAset(Request $request, string $action): void
    {
        $this->requirePermission($request, 'management-aset.aset.'.$action);
    }

    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless(in_array($permission, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenant(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }
}
