<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\MutasiAset;

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
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\MutasiAset\MutasiAsetDetail;
use Modules\Apperp\ManagementAset\Services\DirektoriAset;
use Modules\Apperp\ManagementAset\Services\LocationDimension;
use Modules\Apperp\ManagementAset\Services\NumberSequenceException;
use Modules\Apperp\ManagementAset\Services\PenerbitNomorAset;
use Modules\Apperp\ManagementAset\Support\MutasiStatus;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use stdClass;

/**
 * Dokumen mutasi aset — berita acara serah terima.
 *
 * **Apa yang dipindahkan, dan apa yang tidak.** Dokumen ini memindahkan sumbu fisik dan
 * tanggung jawab: lokasi, unit pengguna, dan penanggung jawab. Padanannya di Dynamics 365
 * adalah `Install asset at location` pada Asset Management, bukan `Transfer fixed assets`
 * pada Fixed assets. Keduanya ada di F&O karena yang kedua **menerbitkan jurnal** — ia
 * memindahkan dimensi keuangan per buku, dengan akun asal dan akun tujuan, sehingga tiap
 * buku memerlukan dimensinya sendiri. Modul ini tidak menjurnal sama sekali, jadi dimensi
 * per buku tidak memiliki arti di sini dan tidak dibangun. Ketika Finance mulai menjurnal
 * dari export penyusutan, di situlah ia menempel: satu tabel dimensi per buku aset, diisi
 * oleh dokumen ini, tanpa membongkar apa pun yang ada di bawah.
 *
 * `financial_dimension_org_unit_id` pada aset tetap diperbarui, dan itu bukan pengecualian
 * terhadap kalimat di atas. Ia satu label pembebanan tingkat aset yang sudah dipelihara
 * jalur penerimaan dan penempatan sejak awal; membiarkannya basi sesudah aset berpindah
 * justru membuat data lebih salah, bukan lebih sedikit.
 *
 * **Status lifecycle aset sengaja tidak disentuh.** Di F&O, memasang aset pada functional
 * location dan mengubah lifecycle state adalah dua tombol yang berbeda pada action pane
 * yang sama. Menggabungkannya membuat aset yang dimutasi ke gudang penyimpanan ikut
 * berstatus dipakai.
 */
class MutasiAsetController extends Controller
{
    private const RESOURCE = 'mutasi-aset';

    /** Sama seperti work order: 160 batas Core dikurangi panjang awalan `mutasi-aset:`. */
    private const MAX_CREATION_KEY = 147;

    private const TABEL = 'aset_tr_mutasi_aset';

    private const TABEL_BARIS = 'aset_tr_mutasi_aset_details';

    public function index(Request $request): JsonResponse
    {
        $this->guard($request, 'read');
        $filter = $request->validate([
            'status' => ['nullable', 'string', Rule::in(MutasiStatus::semua())],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $query = MutasiAset::query();
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
        $mutasi = $this->dokumen($request, $id);
        $mutasi->details = $this->baris($id, $this->tenant($request));

        return response()->json(['data' => $mutasi]);
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
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['tujuan_org_unit_id']);
        $this->validateLookups($request, $data);

        // Nomor diterbitkan setelah seluruh validasi supaya permintaan yang ditolak tidak
        // membakar counter, dan sebelum transaksi supaya kegagalan Core tidak menahan
        // koneksi database — urutan yang sama seperti work order.
        try {
            $kode = $numbers->issue('management-aset.'.self::RESOURCE, $tenant, self::RESOURCE.':'.$key, $data['legal_entity_id']);
        } catch (NumberSequenceException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], NumberSequenceException::HTTP_STATUS);
        }

        try {
            $id = DB::transaction(function () use ($request, $data, $key, $kode): string {
                $record = $this->header($request, $data, $key, $kode);
                (new MutasiAset)->forceFill($record)->save();
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

        $mutasi = $this->dokumen($request, $id);
        $mutasi->details = $this->baris($id, $this->tenant($request));

        return response()->json(['data' => $mutasi], 201, ['Location' => $request->url().'/'.$id]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->guard($request, 'update');
        $mutasi = $this->dokumen($request, $id);
        abort_unless(
            MutasiStatus::dapatDisunting($mutasi->status),
            422,
            'Mutasi yang sudah selesai tidak dapat diubah. Buat mutasi balik bila perlu dikoreksi.',
        );

        $data = $this->validated($request);
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        // Dua jangkauan diperiksa: unit tempat dokumen berada sekarang dan unit tujuan
        // perubahan. Tanpa yang pertama, dokumen dapat dipindahkan keluar dari unit yang
        // tidak boleh disentuh pengguna; tanpa yang kedua, dipindahkan ke unit asing.
        app(OrganizationScope::class)->require($request, $mutasi->legal_entity_id, $mutasi->responsible_org_unit_id);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['responsible_org_unit_id']);
        app(OrganizationScope::class)->require($request, $data['legal_entity_id'], $data['tujuan_org_unit_id']);
        $this->validateLookups($request, $data);

        $changed = DB::transaction(function () use ($request, $id, $version, $data): int {
            $updated = MutasiAset::query()
                ->where(['id' => $id, 'version' => $version, 'status' => MutasiStatus::DRAFT])
                ->update([
                    ...$this->header($request, $data, '', '', false),
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);
            if ($updated) {
                MutasiAsetDetail::query()->where('mutasi_aset_id', $id)->delete();
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
        $mutasi = $this->dokumen($request, $id);
        abort_unless(
            MutasiStatus::dapatDisunting($mutasi->status),
            422,
            'Mutasi yang sudah selesai tidak dapat diarsipkan; penempatan aset sudah berpindah karenanya.',
        );
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        app(OrganizationScope::class)->require($request, $mutasi->legal_entity_id, $mutasi->responsible_org_unit_id);

        $updated = MutasiAset::query()
            ->where(['id' => $id, 'version' => $version, 'status' => MutasiStatus::DRAFT])
            ->update(['deleted_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);

        return $updated ? response()->json(status: 204) : $this->staleVersion();
    }

    /**
     * Menyelesaikan serah terima: penempatan aset benar-benar berpindah.
     *
     * Izinnya `management-aset.aset.mutate`, bukan izin dokumen ini. Menyusun berita acara
     * dan benar-benar memindahkan aset adalah dua wewenang berbeda — juru tulis boleh
     * menyiapkan dokumennya, yang menyerahkan barang yang menyelesaikannya.
     */
    public function selesaikan(Request $request, string $id): JsonResponse
    {
        $this->guardAset($request, 'mutate');
        $mutasi = $this->dokumen($request, $id);
        abort_unless($mutasi->status === MutasiStatus::DRAFT, 422, 'Mutasi ini sudah diselesaikan.');
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        app(OrganizationScope::class)->require($request, $mutasi->legal_entity_id, $mutasi->responsible_org_unit_id);
        app(OrganizationScope::class)->require($request, $mutasi->legal_entity_id, $mutasi->tujuan_org_unit_id);

        $lines = MutasiAsetDetail::query()->where('mutasi_aset_id', $id)->orderBy('line_number')->toBase()->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['details' => 'Mutasi tanpa baris aset tidak dapat diselesaikan.']);
        }

        // Dimensi keuangan lokasi tujuan dibaca sekali, di luar perulangan: seluruh baris
        // pindah ke lokasi yang sama, jadi jawabannya juga sama untuk semuanya.
        $dimensi = $this->dimensiLokasi($mutasi->tujuan_lokasi_id) ?? $mutasi->tujuan_org_unit_id;
        $tenant = $this->tenant($request);

        $changed = DB::transaction(function () use ($request, $mutasi, $id, $version, $lines, $dimensi, $tenant): int {
            // Status dipindahkan lebih dahulu dan dengan `version` sebagai syarat. Dua
            // penyelesaian yang berlomba membuat dua rangkaian penempatan untuk aset yang
            // sama pada tanggal yang sama, dan tidak ada yang dapat menentukan mana yang
            // berlaku. Yang kalah menemukan nol baris terpengaruh dan berhenti di sini.
            $updated = MutasiAset::query()
                ->where(['id' => $id, 'version' => $version, 'status' => MutasiStatus::DRAFT])
                ->update(['status' => MutasiStatus::SELESAI, 'version' => $version + 1, 'updated_at' => now()]);
            if (! $updated) {
                return 0;
            }

            foreach ($lines as $line) {
                $aset = $this->asetUntukDipindah($request, (string) $line->aset_id);

                // Keadaan asal dibekukan pada saat ini, bukan saat dokumen diketik. Berita
                // acara yang dicetak ulang tahun depan harus tetap berbunyi sama walau
                // asetnya sudah berpindah beberapa kali sesudahnya.
                //
                // Penanggung jawab dibaca dari penempatan terakhir, bukan dari aset: aset
                // tidak menyimpan PIC sama sekali di modul ini, dan itu memang benar —
                // siapa yang memegang barang adalah fakta bertanggal, bukan sifat barang.
                MutasiAsetDetail::query()->where('id', $line->id)->update([
                    'asal_lokasi_id' => $aset->lokasi_aset_id,
                    'asal_org_unit_id' => $aset->responsible_org_unit_id,
                    'asal_custodian_user_id' => $this->custodianTerakhir((string) $aset->id),
                    'updated_at' => now(),
                ]);

                // Id penempatan diterbitkan di sini, bukan oleh model: `orderByDesc('id')`
                // dipakai sebagai pemecah seri penempatan bertanggal sama, dan ULID dari
                // model ditulis huruf kecil sehingga tidak berurut terhadap baris lama.
                (new PenempatanAset)->forceFill([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $tenant,
                    'aset_id' => $aset->id,
                    'mutasi_aset_id' => $id,
                    'usage_org_unit_id' => $mutasi->tujuan_org_unit_id,
                    'custodian_user_id' => $mutasi->diterima_oleh_user_id,
                    'lokasi_aset_id' => $mutasi->tujuan_lokasi_id,
                    'effective_on' => $mutasi->tanggal,
                    'reason' => $mutasi->alasan,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->save();

                // Penanggung jawab tidak ikut ditulis ke aset: ia tidak punya kolomnya, dan
                // penempatan di atas sudah merekamnya bertanggal.
                $aset->update([
                    'lokasi_aset_id' => $mutasi->tujuan_lokasi_id,
                    'responsible_org_unit_id' => $mutasi->tujuan_org_unit_id,
                    'financial_dimension_org_unit_id' => $dimensi,
                    // Kondisi yang disaksikan saat serah terima adalah kondisi terakhir yang
                    // diketahui; baris yang tidak menyebutkannya tidak mengubah apa pun.
                    ...($line->kondisi_aset_id ? ['kondisi_aset_id' => $line->kondisi_aset_id] : []),
                ]);
            }

            return $updated;
        });
        if (! $changed) {
            return $this->staleVersion();
        }

        return $this->show($request, $id);
    }

    /**
     * Aset yang siap dipindahkan, dikunci selama transaksi.
     *
     * Dikunci karena dua dokumen dapat menyebut aset yang sama dan diselesaikan
     * bersamaan; tanpa kunci, keduanya membaca keadaan asal yang sama dan berita acara
     * kedua mencatat asal yang sudah tidak benar.
     */
    private function asetUntukDipindah(Request $request, string $asetId): Aset
    {
        $query = Aset::query()->where('id', $asetId)->lockForUpdate();
        app(OrganizationScope::class)->asetQuery($query, $request);
        $aset = $query->first();
        if (! $aset) {
            throw ValidationException::withMessages([
                'details' => 'Aset pada berita acara sudah tidak berada dalam unit kerja yang dapat Anda akses.',
            ]);
        }
        if (! StatusAset::bolehDimutasi($aset->lifecycle_state)) {
            throw ValidationException::withMessages([
                'details' => 'Aset '.$aset->kode.' sudah tidak aktif atau sudah dilepas, sehingga tidak dapat dimutasi.',
            ]);
        }

        return $aset;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = $this->tenant($request);

        $data = $request->validate([
            'legal_entity_id' => ['required', 'ulid'],
            'responsible_org_unit_id' => ['required', 'ulid'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'tujuan_lokasi_id' => ['nullable', 'ulid', Rule::exists('aset_m_lokasi_aset', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'tujuan_org_unit_id' => ['required', 'ulid'],
            'diserahkan_oleh_user_id' => ['nullable', 'string', 'max:64'],
            'diterima_oleh_user_id' => ['nullable', 'string', 'max:64'],
            'alasan' => ['required', 'string', 'max:250'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.aset_id' => ['required', 'ulid'],
            'details.*.kondisi_aset_id' => ['nullable', 'ulid'],
            'details.*.catatan' => ['nullable', 'string', 'max:2000'],
        ]);

        // `details` dijadikan list di sini, bukan dipercayai sudah berupa list.
        // `['required', 'array']` meloloskan objek JSON berkunci teks — `{"a": {...}}`
        // adalah array yang sah bagi Laravel — dan kunci itu terbawa sampai ke penomoran
        // baris, tempat `$index + 1` berhenti sebagai TypeError, bukan sebagai pesan
        // validasi. Menormalkannya di batas membuat anotasi `list<...>` di bawah benar
        // sungguhan, bukan hanya benar menurut PHPDoc.
        $data['details'] = array_values($data['details']);

        return $data;
    }

    /**
     * Aset dan master yang dirujuk wajib berada dalam jangkauan organisasi pengguna, dan
     * asetnya wajib benar-benar dapat dipindahkan.
     *
     * Pemeriksaan ini diulang saat penyelesaian, dan pengulangannya disengaja: di antara
     * penyusunan draf dan serah terimanya, aset dapat didekomisioning oleh orang lain.
     * Yang di sini menolak draf yang mustahil sejak awal, yang di sana menolak draf yang
     * menjadi mustahil sesudahnya.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateLookups(Request $request, array $data): void
    {
        $details = $data['details'];
        $asetIds = array_values(array_unique(array_column($details, 'aset_id')));
        if (count($asetIds) !== count($details)) {
            throw ValidationException::withMessages([
                'details' => 'Satu aset hanya boleh muncul sekali pada satu berita acara.',
            ]);
        }

        $query = Aset::query()->whereIn('id', $asetIds);
        app(OrganizationScope::class)->asetQuery($query, $request);
        $daftarAset = $query->toBase()->get(['id', 'kode', 'lifecycle_state', 'legal_entity_id']);
        if ($daftarAset->count() !== count($asetIds)) {
            throw ValidationException::withMessages([
                'details' => 'Aset tidak ditemukan atau berada di luar unit kerja yang dapat Anda akses.',
            ]);
        }
        foreach ($daftarAset as $aset) {
            // Satu berita acara tidak boleh memindahkan aset milik badan hukum lain.
            // Nomor dokumennya terbit per badan hukum, jadi memuat aset dari badan hukum
            // yang berbeda membuat buktinya bernomor atas nama pihak yang tidak memilikinya
            // — pemeriksaan yang sama sudah ditegakkan dokumen dekomisioning dan pelepasan.
            if ($aset->legal_entity_id !== $data['legal_entity_id']) {
                throw ValidationException::withMessages([
                    'details' => 'Aset '.$aset->kode.' berada di badan hukum lain; berita acara hanya boleh memuat aset milik badan hukum pada dokumen ini.',
                ]);
            }
            if (! StatusAset::bolehDimutasi($aset->lifecycle_state)) {
                throw ValidationException::withMessages([
                    'details' => 'Aset '.$aset->kode.' sudah tidak aktif atau sudah dilepas, sehingga tidak dapat dimutasi.',
                ]);
            }
        }

        $kondisiIds = array_values(array_unique(array_filter(array_column($details, 'kondisi_aset_id'))));
        if ($kondisiIds !== [] && KondisiAset::query()->whereIn('id', $kondisiIds)->where('aktif', true)->count() !== count($kondisiIds)) {
            throw ValidationException::withMessages([
                'details' => 'Kondisi aset tidak ditemukan atau sudah tidak aktif.',
            ]);
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
            'tujuan_org_unit_id' => $data['tujuan_org_unit_id'],
            'alasan' => $data['alasan'],
            'status' => $new ? MutasiStatus::DRAFT : null,
            'version' => $new ? 1 : null,
            'created_at' => $new ? now() : null,
            'updated_at' => now(),
        ], static fn ($value) => $value !== null) + [
            // Di luar `array_filter` karena ketiganya sah bernilai null, dan menyaringnya
            // membuat pengosongan lewat `PATCH` diam-diam tidak tersimpan.
            'tujuan_lokasi_id' => $data['tujuan_lokasi_id'] ?? null,
            'diserahkan_oleh_user_id' => $data['diserahkan_oleh_user_id'] ?? null,
            'diterima_oleh_user_id' => $data['diterima_oleh_user_id'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $details */
    private function gantiBaris(string $mutasiId, array $details): void
    {
        foreach ($details as $index => $detail) {
            MutasiAsetDetail::create([
                'mutasi_aset_id' => $mutasiId,
                'line_number' => $index + 1,
                'aset_id' => $detail['aset_id'],
                'kondisi_aset_id' => $detail['kondisi_aset_id'] ?? null,
                'catatan' => $detail['catatan'] ?? null,
            ]);
        }
    }

    private function replay(string $key): ?stdClass
    {
        return MutasiAset::withTrashed()->where('creation_key', $key)->toBase()->first();
    }

    private function dokumen(Request $request, string $id): stdClass
    {
        $query = MutasiAset::query()->where(self::TABEL.'.id', $id);
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
        $baris->tujuan_org_unit_nama = $direktori->namaUnit($tenantId, $baris->tujuan_org_unit_id ?? null);
        $baris->responsible_org_unit_nama = $direktori->namaUnit($tenantId, $baris->responsible_org_unit_id ?? null);
        $baris->diserahkan_oleh_nama = $direktori->namaOrang($tenantId, $baris->diserahkan_oleh_user_id ?? null);
        $baris->diterima_oleh_nama = $direktori->namaOrang($tenantId, $baris->diterima_oleh_user_id ?? null);

        return $baris;
    }

    /**
     * Nama lokasi tujuan dan jumlah baris ikut dibaca agar daftar tidak perlu satu
     * permintaan tambahan per dokumen.
     *
     * @param  Builder<MutasiAset>  $query
     */
    private function withLookups(Builder $query): QueryBuilder
    {
        return $query
            ->leftJoin('aset_m_lokasi_aset as tujuan', function ($join): void {
                $join->on('tujuan.id', '=', self::TABEL.'.tujuan_lokasi_id')
                    ->on('tujuan.tenant_id', '=', self::TABEL.'.tenant_id');
            })
            ->selectSub(
                MutasiAsetDetail::query()
                    ->selectRaw('count(*)')
                    ->whereColumn(self::TABEL_BARIS.'.mutasi_aset_id', self::TABEL.'.id')
                    ->toBase(),
                'jumlah_baris',
            )
            ->addSelect([
                self::TABEL.'.*',
                'tujuan.kode as tujuan_lokasi_kode',
                'tujuan.nama as tujuan_lokasi_nama',
            ])
            ->toBase();
    }

    /**
     * Baris beserta keadaan asalnya.
     *
     * `asal_*` dari baris dipakai bila sudah dibekukan; selama dokumen masih draf ia
     * kosong dan keadaan asal dibaca langsung dari asetnya, sehingga layar selalu
     * menunjukkan dari mana barang itu akan berpindah **sekarang**, bukan saat diketik.
     *
     * @return Collection<int, stdClass>
     */
    private function baris(string $mutasiId, string $tenantId): Collection
    {
        $direktori = app(DirektoriAset::class);

        // Lokasi asal di-join dua kali, bukan sekali dengan `coalesce` di klausa `on`.
        // Ekspresi di dalam `on` tidak dapat memakai indeks, dan perencana query tidak
        // dapat menyempitkan barisnya lebih dulu; dua join biasa yang dipilih di `select`
        // memakai indeks komposit yang sudah ada pada kedua tabel.
        return MutasiAsetDetail::query()
            ->leftJoin('aset_tr_aset as aset', function ($join): void {
                $join->on('aset.id', '=', self::TABEL_BARIS.'.aset_id')
                    ->on('aset.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_lokasi_aset as asal_beku', function ($join): void {
                $join->on('asal_beku.id', '=', self::TABEL_BARIS.'.asal_lokasi_id')
                    ->on('asal_beku.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_lokasi_aset as asal_kini', function ($join): void {
                $join->on('asal_kini.id', '=', 'aset.lokasi_aset_id')
                    ->on('asal_kini.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            ->leftJoin('aset_m_kondisi_aset as kondisi', function ($join): void {
                $join->on('kondisi.id', '=', self::TABEL_BARIS.'.kondisi_aset_id')
                    ->on('kondisi.tenant_id', '=', self::TABEL_BARIS.'.tenant_id');
            })
            // Penanggung jawab efektif tidak ada di aset; ia adalah penempatan terakhir.
            // Selama dokumen masih draf itulah yang ditampilkan, dan sesudah diselesaikan
            // nilai bekunya yang menang.
            ->selectSub(
                PenempatanAset::query()
                    ->whereColumn('aset_tr_penempatan_aset.aset_id', self::TABEL_BARIS.'.aset_id')
                    ->orderByDesc('aset_tr_penempatan_aset.effective_on')
                    ->orderByDesc('aset_tr_penempatan_aset.id')
                    ->limit(1)
                    ->select('aset_tr_penempatan_aset.custodian_user_id')
                    ->toBase(),
                'asal_custodian_kini',
            )
            // `addSelect`, bukan daftar kolom pada `get()`. `selectSub` di atas sudah
            // mengisi daftar kolom query, dan Laravel mengabaikan argumen `get()` begitu
            // daftar itu tidak lagi null — hasilnya kolom yang diminta hilang tanpa satu
            // pun kesalahan, hanya field yang diam-diam bernilai null.
            ->addSelect([
                self::TABEL_BARIS.'.*',
                'aset.kode as aset_kode',
                'aset.nama as aset_nama',
                'aset.serial_number as aset_serial_number',
                'aset.lokasi_aset_id as asal_lokasi_kini_id',
                'aset.responsible_org_unit_id as asal_org_unit_kini_id',
                'asal_beku.nama as asal_lokasi_beku_nama',
                'asal_kini.nama as asal_lokasi_kini_nama',
                'kondisi.nama as kondisi_aset_nama',
            ])
            ->where(self::TABEL_BARIS.'.mutasi_aset_id', $mutasiId)
            ->orderBy(self::TABEL_BARIS.'.line_number')
            ->toBase()
            ->get()
            // `use`, bukan arrow function: closure biasa tidak menangkap lingkup luar,
            // dan tanpa daftar ini `$direktori` bernilai null di dalam badan closure.
            ->map(function (stdClass $row) use ($direktori, $tenantId): stdClass {
                // Yang dibekukan menang; selama draf, keadaan aset sekarang yang dipakai.
                $row->asal_lokasi_efektif_id = $row->asal_lokasi_id ?? $row->asal_lokasi_kini_id;
                $row->asal_lokasi_nama = $row->asal_lokasi_beku_nama ?? $row->asal_lokasi_kini_nama;
                $row->asal_org_unit_efektif_id = $row->asal_org_unit_id ?? $row->asal_org_unit_kini_id;
                $row->asal_custodian_efektif_id = $row->asal_custodian_user_id ?? $row->asal_custodian_kini;
                // Nama, bukan ULID. Tidak ada yang mengenali unit kerja atau rekan kerjanya
                // dari ULID, jadi id di layar sama saja dengan kolom kosong.
                $row->asal_org_unit_nama = $direktori->namaUnit($tenantId, $row->asal_org_unit_efektif_id);
                $row->asal_custodian_nama = $direktori->namaOrang($tenantId, $row->asal_custodian_efektif_id);

                return $row;
            });
    }

    /** Penanggung jawab pada penempatan terakhir aset; `null` bila belum pernah ada. */
    private function custodianTerakhir(string $asetId): ?string
    {
        $value = PenempatanAset::query()
            ->where('aset_id', $asetId)
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->toBase()
            ->value('custodian_user_id');

        return $value === null ? null : (string) $value;
    }

    /** Pewarisan dimensi dari lokasi, dengan aturan yang sama seperti penerimaan (K-08). */
    private function dimensiLokasi(?string $locationId): ?string
    {
        return app(LocationDimension::class)->resolve($locationId);
    }

    private function staleVersion(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'stale_version',
            'message' => 'Mutasi telah berubah. Muat ulang lalu coba lagi.',
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
