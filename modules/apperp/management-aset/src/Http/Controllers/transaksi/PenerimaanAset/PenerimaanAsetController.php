<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\PenerimaanAset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PembuatAset;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PenerimaanStatus;
use Modules\Apperp\ManagementAset\Support\ValidasiAtributAset;
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
     * Menyelesaikan penerimaan: asetnya benar-benar terdaftar.
     *
     * Izinnya `management-aset.aset.create`, bukan izin dokumen ini. Menyusun berkas
     * penerimaan dan benar-benar menambah aset ke register adalah dua wewenang berbeda —
     * pemisahan yang sama yang sudah dipakai mutasi.
     *
     * Seluruh nomor aset diterbitkan lebih dulu, di luar transaksi, dengan kunci
     * idempoten yang diturunkan dari id dokumen dan nomor urut. Percobaan kedua setelah
     * jaringan putus karena itu memulangkan nomor yang sama, bukan deret baru.
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

        $tenant = $this->tenant($request);
        // Kunci penciptaan disusun lebih dulu supaya penerbitan nomor dan penyimpanan aset
        // memakai kunci yang sama persis; itulah yang membuat percobaan ulang memulangkan
        // aset yang sudah ada alih-alih membuat kembarannya.
        $kunci = [];
        foreach ($lines as $line) {
            for ($urut = 1; $urut <= (int) $line->jumlah; $urut++) {
                $kunci[] = ['line' => $line, 'key' => 'penerimaan:'.$id.':'.$line->line_number.':'.$urut];
            }
        }

        try {
            foreach ($kunci as $i => $satuan) {
                $kunci[$i]['kode'] = $numbers->issue('management-aset.aset', $tenant, 'aset:'.$satuan['key'], $penerimaan->legal_entity_id);
            }
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        $changed = DB::transaction(function () use ($penerimaan, $id, $version, $kunci, $tenant, $pembuat): int {
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

            foreach ($kunci as $satuan) {
                $pembuat->buat($tenant, $satuan['key'], $satuan['kode'], $this->spesifikasiAset($penerimaan, $satuan['line']));
            }

            return $updated;
        });
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
            $diminta = (float) DB::table('aset_tr_permintaan_pengadaan_aset_details')
                ->where('id', $line->permintaan_pembelian_detail_id)
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
     * @return array<string, mixed>
     */
    private function spesifikasiAset(stdClass $penerimaan, stdClass $line): array
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
            'acquisition_value' => $line->nilai_per_unit,
            'residual_value' => $line->residu_per_unit,
            'currency_code' => $penerimaan->currency_code,
            'keterangan' => $line->keterangan,
            'atribut' => $this->atributBaris($line),
            'penerimaan_aset_id' => $penerimaan->id,
            'penerimaan_aset_detail_id' => $line->id,
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

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = $this->tenant($request);
        $milikTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenant)->whereNull('deleted_at');

        $data = $request->validate([
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
            'details.*.residu_per_unit' => ['nullable', 'numeric', 'min:0'],
            'details.*.permintaan_pembelian_detail_id' => ['nullable', 'ulid', Rule::exists('aset_tr_permintaan_pengadaan_aset_details', 'id')->where('tenant_id', $tenant)],
            'details.*.keterangan' => ['nullable', 'string', 'max:2000'],
            'details.*.atribut' => ['sometimes', 'array'],
            'details.*.atribut.*.tipe_atribut_id' => ['required', 'ulid'],
            'details.*.atribut.*.nilai' => ['present'],
        ]);

        // `details` dijadikan list di sini, bukan dipercayai sudah berupa list.
        // `['required', 'array']` meloloskan objek JSON berkunci teks — `{"a": {...}}`
        // adalah array yang sah bagi Laravel — dan kunci itu terbawa sampai ke penomoran
        // baris, tempat `$index + 1` berhenti sebagai TypeError, bukan sebagai pesan
        // validasi.
        $data['details'] = array_values($data['details']);

        return $data;
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
                'residu_per_unit' => $detail['residu_per_unit'] ?? 0,
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
