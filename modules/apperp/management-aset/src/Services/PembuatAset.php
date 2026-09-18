<?php

namespace Modules\Apperp\ManagementAset\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\GroupBukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\master\ProfilPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\TipeAtribut;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AtributAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use Modules\Apperp\ManagementAset\Support\ValidasiAtributAset;
use RuntimeException;
use stdClass;

/**
 * Melahirkan satu aset beserta seluruh yang harus lahir bersamanya.
 *
 * **Kenapa ini sebuah service dan bukan tetap di controller.** Ada dua pintu menuju
 * register: penerimaan satuan lewat layar register, dan dokumen penerimaan yang
 * melahirkan banyak aset sekaligus. Keduanya harus menghasilkan aset yang persis sama —
 * penempatan pertama, buku penyusutan dari matriks group x book, ambang kapitalisasi, dan
 * atribut yang diwarisi jenis aset. Dua salinan aturan itu akan berbeda pada perbaikan
 * pertama yang hanya masuk ke salah satunya.
 *
 * Service ini **tidak** membuka transaksi dan **tidak** menerbitkan nomor. Keduanya milik
 * pemanggilnya: dokumen penerimaan menerbitkan dua puluh nomor sekaligus lalu menyimpan
 * dua puluh aset di dalam satu transaksi, dan itu hanya mungkin bila keputusannya ada di
 * atas, bukan di dalam.
 */
class PembuatAset
{
    /**
     * Membuat satu aset: baris register, penempatan pertama, buku penyusutan, atribut.
     *
     * Bentuk `$data` sengaja sama dengan payload layar register, supaya satu-satunya
     * perbedaan antara kedua pintu adalah dari mana nilainya berasal.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(string $tenantId, string $creationKey, string $kode, array $data): Aset
    {
        $defaults = $this->bawaanGroup((string) $data['group_aset_id']);
        // Lokasi bawaan group hanya mengisi kekosongan. Begitu aset terbentuk, lokasinya
        // adalah miliknya sendiri: mengubah bawaan group kelak tidak memindahkan aset
        // mana pun, sama seperti perlakuan referensi fiskal di bawah.
        $lokasiId = $data['lokasi_aset_id'] ?? $defaults?->lokasi_aset_id;

        $aset = Aset::query()->create([
            'tenant_id' => $tenantId,
            'creation_key' => $creationKey,
            'kode' => $kode,
            'nama' => $data['nama'],
            'legal_entity_id' => $data['legal_entity_id'],
            'responsible_org_unit_id' => $data['usage_org_unit_id'],
            'group_aset_id' => $data['group_aset_id'],
            // Disalin saat penerimaan supaya perubahan pilihan fiskal pada group
            // tidak mengubah jejak aturan aset yang sudah aktif.
            'kelompok_harta_fiskal_id' => $defaults?->kelompok_harta_fiskal_id,
            'jenis_aset_id' => $data['jenis_aset_id'],
            'kondisi_aset_id' => $data['kondisi_aset_id'] ?? null,
            'pabrikan_aset_id' => $data['pabrikan_aset_id'] ?? null,
            'model_aset_id' => $data['model_aset_id'] ?? null,
            'induk_aset_id' => $data['induk_aset_id'] ?? null,
            'lokasi_aset_id' => $lokasiId,
            'financial_dimension_org_unit_id' => $this->dimensiLokasi($lokasiId) ?? $data['usage_org_unit_id'],
            'serial_number' => $data['serial_number'] ?? null,
            'model_number' => $data['model_number'] ?? null,
            'acquired_on' => $data['acquired_on'],
            'placed_in_service_on' => $data['placed_in_service_on'] ?? null,
            'acquisition_value' => $data['acquisition_value'],
            'currency_code' => strtoupper((string) $data['currency_code']),
            'lifecycle_state' => StatusAset::DITERIMA,
            'keterangan' => $data['keterangan'] ?? null,
            'penerimaan_aset_id' => $data['penerimaan_aset_id'] ?? null,
            'penerimaan_aset_detail_id' => $data['penerimaan_aset_detail_id'] ?? null,
        ]);

        // Id tetap diterbitkan di sini, bukan diserahkan ke model: `orderByDesc('id')`
        // dipakai sebagai pemecah seri penempatan bertanggal sama, dan ULID dari model
        // ditulis huruf kecil sehingga tidak berurut terhadap baris lama yang huruf besar.
        (new PenempatanAset)->forceFill([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'aset_id' => $aset->id,
            'receiving_org_unit_id' => $data['receiving_org_unit_id'] ?? null,
            'usage_org_unit_id' => $data['usage_org_unit_id'] ?? null,
            'received_by_user_id' => $data['received_by_user_id'] ?? null,
            'custodian_user_id' => $data['custodian_user_id'] ?? null,
            'lokasi_aset_id' => $lokasiId,
            'effective_on' => $data['acquired_on'],
            'reason' => 'Penerimaan aset',
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->buatBuku($aset, $data, $tenantId);
        $this->simpanAtribut($aset, $data, $tenantId);

        return $aset;
    }

    /**
     * Ambang kapitalisasi milik group, atau `null` bila group tidak menetapkannya.
     *
     * Dibuka ke luar supaya layar dapat memperingatkan sebelum dokumen diselesaikan:
     * perolehan di bawah ambang tetap tercatat sebagai aset tetapi tidak pernah
     * menyusut, dan itu keputusan yang jauh lebih murah diketahui sebelum dua puluh
     * nomor aset terlanjur terbit.
     */
    public function ambangKapitalisasi(string $groupAsetId): ?string
    {
        $nilai = GroupAset::withTrashed()
            ->where('id', $groupAsetId)
            ->toBase()->value('capitalization_threshold');

        return $nilai === null ? null : (string) $nilai;
    }

    /**
     * Menyimpan nilai atribut aset. Atribut diwarisi dari jenis aset, jadi yang
     * diperiksa adalah definisi milik jenis yang dipilih, bukan daftar tetap di kode.
     *
     * @param  array<string, mixed>  $data
     */
    public function simpanAtribut(Aset $aset, array $data, string $tenantId): void
    {
        $rows = app(ValidasiAtributAset::class)->rowsFor(
            $tenantId,
            (string) $data['jenis_aset_id'],
            $data['atribut'] ?? [],
        );
        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            (new AtributAset)->forceFill([...$row, 'aset_id' => $aset->id])->save();
        }
        // `withTrashed()`: tipe atribut yang sudah diarsipkan pun tetap dikunci tipenya,
        // karena nilai yang baru saja tersimpan tetap merujuk padanya.
        TipeAtribut::withTrashed()
            ->whereIn('id', collect($rows)->pluck('tipe_atribut_id')->unique()->all())
            ->update(['data_type_locked' => true, 'updated_at' => now()]);
    }

    /**
     * Tahun buku yang memuat tanggal mulai digunakan, dibaca dari Core.
     *
     * Hanya dipanggil bila memang menentukan hasil: profil berdasar tahun fiskal dan
     * konvensinya bergeser mengikuti batas tahun. Untuk kombinasi lain, batas tahun
     * tidak dipakai sama sekali sehingga tidak perlu memanggil Core.
     *
     * Kalender yang belum disiapkan tenant tidak menggagalkan penerimaan aset:
     * perhitungan jatuh ke tahun kalender, sama seperti sebelum kalender diisi.
     *
     * @return array{starts_on: string, ends_on: string}|null
     */
    public function tahunFiskal(string $tenantId, string $legalEntityId, string $placedInService, ?string $profileId, ?string $convention): ?array
    {
        $yearBoundConventions = ['half_year', 'half_year_start_of_year', 'half_year_next_year'];
        if (! $profileId || ! in_array($convention, $yearBoundConventions, true)) {
            return null;
        }
        $yearBasis = ProfilPenyusutan::withTrashed()
            ->where('id', $profileId)
            ->toBase()->value('year_basis');
        if ($yearBasis !== 'fiscal') {
            return null;
        }

        try {
            $fiscal = app(KalenderFiskalAset::class)->resolve($tenantId, $legalEntityId, $placedInService);
        } catch (RuntimeException) {
            return null;
        }

        return $fiscal['year'] ?? null;
    }

    /**
     * Ambil profil yang benar-benar dapat dipakai oleh calculator. Validasi ini juga
     * melindungi data legacy yang dibuat sebelum matriks menjadi sumber konfigurasi.
     */
    public function profilTerhitung(
        ?string $profileId,
        mixed $usefulLifeOverride,
        ?string $conventionOverride,
        bool $checkEffectiveDate,
        ?string $effectiveOn = null,
    ): stdClass {
        if (! $profileId) {
            throw ValidationException::withMessages([
                'group_aset_id' => 'Buku penyusutan belum memiliki profil utama yang efektif. Pilih profil pada matriks group x book atau pada Buku penyusutan.',
            ]);
        }

        $profile = ProfilPenyusutan::query()
            ->where(['id' => $profileId, 'aktif' => true])
            ->toBase()->first([
                'id', 'method', 'frequency', 'convention', 'useful_life_periods', 'rate_percent',
                'manual_schedule', 'effective_from', 'effective_to',
            ]);
        if (! $profile) {
            throw ValidationException::withMessages([
                'group_aset_id' => 'Profil penyusutan pada Buku atau matriks sudah tidak aktif. Pilih profil yang masih berlaku.',
            ]);
        }

        $errors = [];
        if (! in_array($profile->method, ProfilPenyusutan::METHODS, true)) {
            $errors['group_aset_id'] = 'Metode profil penyusutan belum dapat dihitung oleh aplikasi.';
        }
        if (! in_array($profile->frequency, ProfilPenyusutan::FREQUENCIES, true)) {
            $errors['group_aset_id'] = 'Frekuensi profil penyusutan belum dapat dihitung oleh aplikasi.';
        }
        $convention = $conventionOverride ?? $profile->convention;
        if ($convention !== null && ! in_array($convention, BukuPenyusutan::CONVENTIONS, true)) {
            $errors['group_aset_id'] = 'Konvensi penyusutan pada Buku atau profil tidak dikenal.';
        }
        $usefulLife = $usefulLifeOverride ?? $profile->useful_life_periods;
        if (in_array($profile->method, ['straight_line', 'straight_line_life_remaining', 'reducing_balance'], true) && ! $usefulLife) {
            $errors['group_aset_id'] = 'Masa manfaat belum diisi pada matriks atau profil penyusutan.';
        }
        if ($profile->method === 'reducing_balance' && ($profile->rate_percent === null || (float) $profile->rate_percent <= 0)) {
            $errors['group_aset_id'] = 'Profil saldo menurun harus memiliki persentase per tahun yang lebih besar dari 0.';
        }
        if ($profile->method === 'manual' && ! $this->adaJadwalManual($profile->manual_schedule)) {
            $errors['group_aset_id'] = 'Profil dengan jadwal manual belum memiliki nilai penyusutan per periode.';
        }
        if ($checkEffectiveDate && $effectiveOn !== null) {
            $date = substr($effectiveOn, 0, 10);
            if ($profile->effective_from !== null && $date < substr((string) $profile->effective_from, 0, 10)) {
                $errors['effective_on'] = 'Profil penyusutan belum berlaku pada tanggal penempatan aset.';
            }
            if ($profile->effective_to !== null && $date > substr((string) $profile->effective_to, 0, 10)) {
                $errors['effective_on'] = 'Profil penyusutan sudah tidak berlaku pada tanggal penempatan aset.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $profile;
    }

    /**
     * Dimensi keuangan yang diwarisi aset dari lokasi fisiknya; padanan toggle
     * "Update asset dimension" pada Functional location type di F&O. Lokasi yang tidak
     * dipetakan ke unit organisasi mengembalikan null, dan aset tetap memakai unit
     * penggunanya sendiri.
     */
    public function dimensiLokasi(?string $locationId): ?string
    {
        if (! $locationId) {
            return null;
        }

        // `withTrashed()`: lokasi yang sudah diarsipkan tetap membawa pemetaan dimensinya,
        // sama seperti sebelum query ini melewati model.
        return LokasiAset::withTrashed()
            ->where('id', $locationId)
            ->toBase()->value('org_unit_id');
    }

    /**
     * Group ditunjuk langsung oleh aset, jadi default penyusutan dibaca dengan satu
     * lookup. Sebelumnya nilai ini diraih dengan menyusuri jenis -> kategori -> group,
     * yang membuat rantai klasifikasi wajib ada semata-mata sebagai jalur lookup.
     *
     * Hasilnya baris mentah `toBase()` — sebuah `stdClass`, bukan model.
     */
    public function bawaanGroup(string $groupAsetId): ?stdClass
    {
        return GroupAset::withTrashed()
            ->where('id', $groupAsetId)
            ->select('kelompok_harta_fiskal_id', 'lokasi_aset_id')
            ->toBase()->first();
    }

    /**
     * Buku aset dibentuk dari matriks group x buku: satu baris matriks menghasilkan satu
     * buku, sehingga aset dapat menyusut komersial dan fiskal sekaligus dengan aturannya
     * masing-masing. Aturan matriks disalin ke buku, bukan dirujuk hidup-hidup, supaya
     * perubahan matriks kelak tidak menulis ulang aset yang sudah berjalan.
     *
     * Bila matriks belum diisi, aset tetap boleh tercatat sebagai `received`, tetapi tidak
     * dapat ditempatkan atau mulai disusutkan sampai matriks menyediakan buku dan profil.
     *
     * @param  array<string, mixed>  $data
     */
    private function buatBuku(Aset $aset, array $data, string $tenantId): void
    {
        // `withTrashed()` di sini dan pada `bawaanGroup()` mempertahankan perilaku lama:
        // group yang sudah diarsipkan tetap terbaca, karena aset yang sedang diterima
        // memang sudah lolos validasi yang menuntut group masih hidup.
        $threshold = $this->ambangKapitalisasi((string) $data['group_aset_id']);
        // Perolehan di bawah ambang kapitalisasi tetap dicatat sebagai aset, tetapi
        // bukunya tidak menyusut. Ini perilaku yang sama dengan F&O. Perbandingannya
        // per aset, bukan per dokumen: dua puluh kursi lima ratus ribu tidak melewati
        // ambang sepuluh juta hanya karena datang bersamaan.
        $capitalized = $threshold === null || (float) $data['acquisition_value'] >= (float) $threshold;

        $rows = GroupBukuPenyusutan::query()
            ->join('aset_m_buku_penyusutan as buku', function ($join): void {
                $join->on('buku.id', '=', 'aset_m_group_buku_penyusutan.buku_id')->on('buku.tenant_id', '=', 'aset_m_group_buku_penyusutan.tenant_id');
            })
            ->where('aset_m_group_buku_penyusutan.group_aset_id', $data['group_aset_id'])
            ->whereNull('buku.deleted_at')
            ->where('buku.aktif', true)
            ->select(
                'aset_m_group_buku_penyusutan.buku_id',
                'aset_m_group_buku_penyusutan.useful_life_periods',
                'aset_m_group_buku_penyusutan.convention',
                'aset_m_group_buku_penyusutan.depreciate',
                DB::raw('coalesce(aset_m_group_buku_penyusutan.round_off_depreciation, buku.round_off_depreciation) as round_off_depreciation'),
                DB::raw('coalesce(aset_m_group_buku_penyusutan.depreciation_profile_id, buku.depreciation_profile_id) as depreciation_profile_id'),
                DB::raw('coalesce(aset_m_group_buku_penyusutan.alternative_profile_id, buku.alternative_profile_id) as alternative_profile_id'),
                'buku.kode as buku_code',
            )
            ->toBase()->get();

        if ($rows->isEmpty()) {
            return;
        }

        $calculator = app(DepreciationCalculator::class);
        // F&O menghitung penyusutan dari tanggal aset mulai digunakan, bukan tanggal
        // perolehan. Bila belum diisi, tanggal perolehan menjadi cadangannya.
        $placedInService = $data['placed_in_service_on'] ?? $data['acquired_on'];

        foreach ($rows as $row) {
            // Buku yang memang tidak menghitung tidak memerlukan profil. F&O pun tidak
            // menuntutnya pada buku dengan "Calculate depreciation = No". Tanpa ini,
            // group yang sengaja tidak disusutkan dan aset di bawah ambang kapitalisasi
            // sama-sama memaksa tenant mengarang profil yang tidak pernah dipakai.
            $depreciates = $capitalized && (bool) $row->depreciate;
            $profile = $depreciates ? $this->profilTerhitung(
                $row->depreciation_profile_id,
                $row->useful_life_periods,
                $row->convention,
                checkEffectiveDate: false,
            ) : null;
            if ($depreciates && $row->alternative_profile_id) {
                $this->profilTerhitung($row->alternative_profile_id, null, null, checkEffectiveDate: false);
            }
            $usefulLife = $row->useful_life_periods ?? $profile?->useful_life_periods;
            $convention = $row->convention ?? $profile?->convention;
            BukuAset::query()->create([
                'tenant_id' => $tenantId,
                'aset_id' => $aset->id,
                'buku_id' => $row->buku_id,
                'depreciation_profile_id' => $row->depreciation_profile_id,
                'alternative_profile_id' => $row->alternative_profile_id,
                'book_code' => $row->buku_code,
                'useful_life_periods' => $usefulLife,
                'convention' => $convention,
                'depreciation_start_on' => $calculator->startDate(
                    $placedInService,
                    $convention,
                    $this->tahunFiskal($tenantId, (string) $data['legal_entity_id'], $placedInService, $row->depreciation_profile_id, $convention),
                )->toDateString(),
                'depreciate' => $depreciates,
                'round_off_depreciation' => $row->round_off_depreciation ?? 0,
                'acquisition_value' => $data['acquisition_value'],
                'residual_value' => $data['residual_value'] ?? 0,
                'accumulated_depreciation' => 0,
                'net_book_value' => $data['acquisition_value'],
                'status' => 'active',
            ]);
        }
    }

    private function adaJadwalManual(mixed $schedule): bool
    {
        if (is_string($schedule)) {
            $schedule = json_decode($schedule, true);
        }

        return is_array($schedule) && $schedule !== [] && collect($schedule)->every(
            fn (mixed $row): bool => is_array($row) && array_key_exists('amount', $row) && is_numeric($row['amount']) && (float) $row['amount'] >= 0,
        );
    }
}
