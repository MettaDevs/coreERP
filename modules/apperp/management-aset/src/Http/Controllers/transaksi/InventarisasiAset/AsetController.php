<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\transaksi\InventarisasiAset;

use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AtributAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Services\AcquisitionAdjustment;
use Modules\Apperp\ManagementAset\Services\AcquisitionAdjustmentFailed;
use Modules\Apperp\ManagementAset\Services\DepreciationCalculator;
use Modules\Apperp\ManagementAset\Services\PembuatAset;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;
use Modules\Apperp\ManagementAset\Support\PostingCheckLines;
use Modules\Apperp\ManagementAset\Support\StatusAset;

class AsetController extends Controller
{
    private const RESOURCE = 'aset';

    private const VALUE_LOCKED = 'Nilai perolehan dan residu tidak dapat diubah setelah ada periode penyusutan. Balikkan periodenya terlebih dahulu.';

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = app(OrganizationScope::class)->asetQuery(Aset::query(), $request);
        if (($q = trim((string) ($data['q'] ?? ''))) !== '') {
            $query->where(fn ($builder) => $builder
                ->whereRaw('LOWER(kode) LIKE ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereRaw('LOWER(nama) LIKE ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereRaw('LOWER(serial_number) LIKE ?', ['%'.mb_strtolower($q).'%']));
        }

        return response()->json(['data' => $query->orderByDesc('created_at')->get()->map($this->present(...))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $aset = app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->findOrFail($id);

        return response()->json(['data' => [
            ...$this->present($aset),
            'atribut' => $this->attributesOf($aset->id),
        ]]);
    }

    /**
     * Koreksi data aset yang sudah diterima.
     *
     * Register aset sebelumnya hanya dapat ditulis satu kali, sehingga satu salah pilih
     * jenis atau tanggal mulai digunakan hanya bisa diperbaiki dengan membuat aset baru.
     * Yang boleh berubah dibatasi oleh apa yang sudah terlanjur diturunkan darinya:
     * group tidak dapat diganti karena buku aset sudah dibentuk dari matriksnya, dan
     * nilai perolehan tidak dapat diganti setelah ada periode penyusutan yang berjalan.
     */
    public function update(Request $request, string $id, PembuatAset $pembuat): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $tenantId = $this->tenantId($request);
        $aset = app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->findOrFail($id);
        abort_unless(
            StatusAset::bolehDikoreksi($aset->lifecycle_state),
            409,
            'Aset yang sudah dilepas tidak dapat diubah.'
        );

        $rules = $this->rules($tenantId);
        $editable = [
            'nama', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'induk_aset_id',
            'serial_number', 'model_number', 'placed_in_service_on', 'acquisition_value', 'residual_value',
            'keterangan', 'atribut', 'atribut.*.tipe_atribut_id', 'atribut.*.nilai',
        ];
        $data = $request->validate([
            ...array_intersect_key($rules, array_flip($editable)),
            // Seluruh field bersifat opsional pada koreksi; yang tidak dikirim tidak berubah.
            'nama' => ['sometimes', ...$rules['nama']],
            'jenis_aset_id' => ['sometimes', ...$rules['jenis_aset_id']],
            'acquisition_value' => ['sometimes', ...$rules['acquisition_value']],
            // Koreksi nilai perolehan menerbitkan jurnal koreksi (TODO 12): alasannya ikut ke keterangan
            // jurnal, dan tanggalnya hari koreksi dilakukan menurut jam pengguna (K-34, K-36). Keduanya
            // baru wajib bila nilainya benar-benar berubah; lihat `adjustmentBlockers()`.
            'reason' => ['nullable', 'string', 'max:250'],
            'adjustment_date' => ['nullable', 'date_format:Y-m-d'],
            // Ditolak lebih awal dengan pesan yang menjelaskan alasannya, bukan diabaikan
            // diam-diam sehingga pengguna mengira group sudah berganti.
            'group_aset_id' => ['prohibited'],
        ], ['group_aset_id.prohibited' => 'Group aset menentukan buku penyusutan yang sudah terbentuk, jadi tidak dapat diganti di sini.']);

        abort_if(
            ($data['induk_aset_id'] ?? null) === $aset->id,
            422,
            'Aset tidak dapat menjadi induk dirinya sendiri.'
        );

        $periods = $this->hasPeriods($aset->id);
        $touchesValue = array_key_exists('acquisition_value', $data) || array_key_exists('residual_value', $data);
        abort_if(
            $periods && $touchesValue,
            409,
            self::VALUE_LOCKED
        );

        try {
            [$aset, $koreksi] = DB::transaction(function () use ($aset, $data, $tenantId, $periods, $touchesValue, $pembuat): array {
                // Satu aset dapat dikoreksi dari beberapa instance API sekaligus. Kunci
                // register aset lebih dulu agar penggantian baris atribut tidak saling
                // menyelip di antara delete dan insert — dan supaya nomor urut jurnal
                // koreksinya tidak pernah dipakai dua kali.
                $asetTerkunci = Aset::query()
                    ->where('id', $aset->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    StatusAset::bolehDikoreksi($asetTerkunci->lifecycle_state),
                    409,
                    'Aset yang sudah dilepas tidak dapat diubah.'
                );

                // Selisih dihitung dari nilai yang terkunci, bukan dari yang dibaca sebelum transaksi:
                // koreksi serentak kedua melihat nilai yang sudah dikoreksi yang pertama.
                $sebelum = (string) $asetTerkunci->acquisition_value;
                $koreksiNilai = array_key_exists('acquisition_value', $data)
                    && ! BigDecimal::of((string) $data['acquisition_value'])->isEqualTo($sebelum);
                if ($koreksiNilai && ($masalah = $this->adjustmentBlockers($asetTerkunci, $data)) !== []) {
                    throw ValidationException::withMessages($masalah);
                }

                if (array_intersect(array_keys($data), ['jenis_aset_id', 'pabrikan_aset_id', 'model_aset_id']) !== []) {
                    $this->assertModelCombination(
                        array_key_exists('jenis_aset_id', $data) ? $data['jenis_aset_id'] : $asetTerkunci->jenis_aset_id,
                        array_key_exists('pabrikan_aset_id', $data) ? $data['pabrikan_aset_id'] : $asetTerkunci->pabrikan_aset_id,
                        array_key_exists('model_aset_id', $data) ? $data['model_aset_id'] : $asetTerkunci->model_aset_id,
                    );
                }

                $asetTerkunci->update(array_intersect_key($data, array_flip([
                    'nama', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'induk_aset_id',
                    'serial_number', 'model_number', 'placed_in_service_on', 'acquisition_value', 'residual_value', 'keterangan',
                ])));

                if ($touchesValue) {
                    $this->applyValueChange($asetTerkunci, $data);
                }
                // Tanggal mulai digunakan hanya boleh menggeser buku yang belum menyusut.
                // Buku yang sudah berjalan memakai tanggal itu sebagai dasar periode yang
                // terlanjur final, jadi menggesernya membuat riwayatnya tidak konsisten.
                if (array_key_exists('placed_in_service_on', $data) && ! $periods) {
                    $this->recalculateStartDates($asetTerkunci, $tenantId);
                }
                // Mengganti jenis aset mengganti definisi atributnya, jadi nilainya wajib
                // dikirim ulang: nilai lama milik jenis lama tidak dapat dipercaya lagi.
                if (array_key_exists('atribut', $data) || array_key_exists('jenis_aset_id', $data)) {
                    AtributAset::query()->where('aset_id', $asetTerkunci->id)->delete();
                    $pembuat->simpanAtribut($asetTerkunci, ['jenis_aset_id' => $asetTerkunci->jenis_aset_id, 'atribut' => $data['atribut'] ?? []], $tenantId);
                }

                // Jurnal koreksinya terbit di transaksi yang sama: nilai yang gagal dijurnal tidak berubah,
                // dan nilai yang berubah pasti membawa jurnalnya — atau catatan kenapa tidak (K-35).
                $koreksi = $koreksiNilai
                    ? app(AcquisitionAdjustment::class)->publish($asetTerkunci, $sebelum, (string) $data['acquisition_value'], (string) $data['adjustment_date'], trim((string) $data['reason']))
                    : null;

                return [$asetTerkunci, $koreksi];
            });
        } catch (AcquisitionAdjustmentFailed $kegagalan) {
            return $this->adjustmentFailed($kegagalan);
        }

        $aset->refresh();

        return response()->json(['data' => [
            ...$this->present($aset),
            'atribut' => $this->attributesOf($aset->id),
            'adjustment' => $koreksi === null ? null : [
                'note' => $koreksi['note'],
                'posting' => $koreksi['posting'] === null ? null : [
                    'posting_id' => $koreksi['posting']['posting_id'],
                    'status' => $koreksi['posting']['status'],
                ],
            ],
        ]]);
    }

    /**
     * Pratinjau koreksi nilai perolehan (TODO 12, K-36): selisihnya, jurnal koreksi yang akan terbit
     * beserta masalahnya, atau catatan kenapa tidak ada jurnal. Penghalangnya sama dengan yang ditegakkan
     * saat menyimpan, kecuali alasan yang boleh masih kosong selama pengguna mengetik. Tidak menyimpan apa pun.
     */
    public function adjustmentPreview(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'update');
        $aset = app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->findOrFail($id);
        $rules = $this->rules($this->tenantId($request));
        $data = $request->validate([
            'acquisition_value' => $rules['acquisition_value'],
            'adjustment_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:250'],
        ]);
        $sebelum = (string) $aset->acquisition_value;
        $sesudah = (string) $data['acquisition_value'];

        $masalah = match (true) {
            ! StatusAset::bolehDikoreksi($aset->lifecycle_state) => ['acquisition_value' => 'Aset yang sudah dilepas tidak dapat diubah.'],
            $this->hasPeriods($aset->id) => ['acquisition_value' => self::VALUE_LOCKED],
            default => array_diff_key($this->adjustmentBlockers($aset, $data), ['reason' => true]),
        };
        try {
            $hasil = $masalah === [] && ! BigDecimal::of($sesudah)->isEqualTo($sebelum)
                ? app(AcquisitionAdjustment::class)->preview($aset, $sesudah, (string) $data['adjustment_date'], trim((string) ($data['reason'] ?? '')))
                : ['difference' => (string) BigDecimal::of($sesudah)->minus($sebelum), 'note' => null, 'posting' => null];
        } catch (AcquisitionAdjustmentFailed $kegagalan) {
            return $this->adjustmentFailed($kegagalan);
        }
        $posting = $hasil['posting'];
        $payload = $posting['payload'] ?? null;

        return response()->json(['data' => [
            'before' => $sebelum,
            'after' => $sesudah,
            'difference' => $hasil['difference'],
            'currency_code' => $aset->currency_code,
            'blockers' => array_map(static fn (string $field, string $message): array => ['field' => $field, 'message' => $message], array_keys($masalah), array_values($masalah)),
            'note' => $hasil['note'],
            'posting' => $posting === null ? null : [
                'posting_id' => $posting['posting_id'],
                'status' => $posting['status'],
                'posting_date' => $payload['posting_date'] ?? null,
                'currency' => $payload['currency'] ?? null,
                'total' => $payload['totals']['debit'] ?? null,
                'lines' => PostingCheckLines::from($payload),
                'problems' => $posting['problems'],
            ],
        ]]);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $this->requirePermission($request, 'read');
        $aset = app(OrganizationScope::class)->asetQuery(Aset::query(), $request)->findOrFail($id);
        $placements = PenempatanAset::query()
            ->where('aset_id', $aset->id)
            ->orderBy('effective_on')->orderBy('created_at')->toBase()->get();

        return response()->json(['data' => ['aset' => $this->present($aset), 'placements' => $placements]]);
    }

    /** @return array<string, array<int, mixed>> */
    /**
     * Aturan koreksi aset.
     *
     * Hanya berisi field yang memang boleh dikoreksi setelah aset lahir. Sampai 18
     * September 2026 method ini melayani dua pemanggil — penerimaan dan koreksi — dan
     * memuat seluruh field penerimaan sekaligus. Setelah `POST /aset` dipensiunkan,
     * separuh isinya menjadi aturan yang tidak pernah lagi diperiksa terhadap apa pun.
     *
     * Yang lahir bersama aset dan tidak ada di sini: badan hukum, group, lokasi awal,
     * tanggal perolehan, mata uang, unit penerima, dan penanggung jawab pertama. Semuanya
     * milik dokumen penerimaan; mengoreksinya berarti mengoreksi bukti penerimaannya,
     * bukan asetnya.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(string $tenantId): array
    {
        $sameTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at');

        return [
            'nama' => ['required', 'string', 'max:150'],
            'jenis_aset_id' => ['required', 'ulid', $sameTenant('aset_m_jenis_aset')],
            'kondisi_aset_id' => ['nullable', 'ulid', $sameTenant('aset_m_kondisi_aset')],
            'pabrikan_aset_id' => ['nullable', 'ulid', $sameTenant('aset_m_pabrikan_aset')],
            'model_aset_id' => ['nullable', 'ulid', $sameTenant('aset_m_model_aset')],
            'induk_aset_id' => ['nullable', 'ulid', Rule::exists('aset_tr_aset', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'serial_number' => ['nullable', 'string', 'max:150'], 'model_number' => ['nullable', 'string', 'max:150'],
            'placed_in_service_on' => ['nullable', 'date'],
            // Kolomnya berpresisi dua desimal: nilai yang lebih halus akan dibulatkan database tanpa kabar,
            // dan register tidak lagi sama dengan yang diketik pengguna.
            'acquisition_value' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'residual_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            // Bentuk atribut divalidasi di sini; isinya divalidasi terhadap definisi
            // milik jenis aset, yang hanya diketahui saat berjalan.
            'atribut' => ['sometimes', 'array'],
            'atribut.*.tipe_atribut_id' => ['required', 'ulid'],
            'atribut.*.nilai' => ['present'],
        ];
    }

    /**
     * F&O hanya membolehkan kombinasi model yang memang dikaitkan dengan jenis aset.
     * Model tanpa jenis tetap boleh dipakai selama jenis tersebut belum memiliki model
     * yang dikaitkan. Pabrikan model selalu harus sama dengan pabrikan pada aset.
     */
    private function assertModelCombination(
        ?string $jenisAsetId,
        ?string $pabrikanAsetId,
        ?string $modelAsetId,
    ): void {
        if (! $modelAsetId) {
            return;
        }

        // `toBase()` menjalankan query lewat model — global scope tenant dan soft delete
        // sudah tersisip di dalamnya — tetapi memulangkan baris apa adanya, bukan model,
        // sehingga nilai yang dibaca tetap sama persis seperti saat query masih mentah.
        $model = ModelAset::query()
            ->where('id', $modelAsetId)
            ->toBase()
            ->first(['pabrikan_aset_id', 'jenis_aset_id', 'aktif']);

        if (! $model) {
            return;
        }

        if (! $model->aktif) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model yang dipilih sudah tidak aktif.',
            ]);
        }

        if (! $pabrikanAsetId || $model->pabrikan_aset_id !== $pabrikanAsetId) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model harus berasal dari pabrikan yang dipilih.',
            ]);
        }

        if (! $jenisAsetId) {
            return;
        }

        $hasConfiguredModels = ModelAset::query()
            ->where(['jenis_aset_id' => $jenisAsetId, 'aktif' => true])
            ->exists();

        if ($hasConfiguredModels && $model->jenis_aset_id !== $jenisAsetId) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model ini belum dikaitkan dengan jenis aset yang dipilih.',
            ]);
        }

        if (! $hasConfiguredModels && $model->jenis_aset_id !== null) {
            throw ValidationException::withMessages([
                'model_aset_id' => 'Model ini hanya dapat dipakai pada jenis aset yang sudah dikaitkan dengannya.',
            ]);
        }
    }

    /** Aset ini sudah punya periode penyusutan di buku mana pun. */
    private function hasPeriods(string $asetId): bool
    {
        return DepreciationPeriod::query()
            ->join('aset_tr_buku_aset as book', function ($join): void {
                $join->on('book.id', '=', 'aset_tr_penyusutan_aset.buku_aset_id')->on('book.tenant_id', '=', 'aset_tr_penyusutan_aset.tenant_id');
            })
            ->where('book.aset_id', $asetId)
            ->exists();
    }

    /**
     * Yang menahan koreksi nilai perolehan, berkunci field; kosong berarti boleh.
     *
     * Alasan wajib karena ikut ke keterangan jurnal koreksi (K-36). Tanggalnya hari koreksi dilakukan
     * menurut jam pengguna (K-34): layar mengirim tanggal lokalnya sendiri, dan server hanya menerima
     * hari ini plus-minus satu hari. Selisih itu menampung zona waktu mana pun tanpa membuka jalan untuk
     * memundurkan tanggal jurnal.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function adjustmentBlockers(Aset $aset, array $data): array
    {
        $masalah = [];
        if (trim((string) ($data['reason'] ?? '')) === '') {
            $masalah['reason'] = 'Tulis alasan koreksi nilai perolehan. Alasannya ikut ke keterangan jurnal koreksi di aplikasi finance.';
        }
        $tanggal = $data['adjustment_date'] ?? null;
        if (! is_string($tanggal) || $tanggal === '') {
            $masalah['adjustment_date'] = 'Tanggal koreksi belum terkirim. Muat ulang halaman, lalu simpan lagi.';
        } elseif (abs(Carbon::parse($tanggal)->startOfDay()->diffInDays(now()->startOfDay(), false)) > 1) {
            $masalah['adjustment_date'] = 'Tanggal koreksi harus tanggal hari ini: jurnal koreksi masuk ke periode yang sedang berjalan.';
        }

        return $masalah + app(AcquisitionAdjustment::class)->blockers($aset, (string) $data['acquisition_value']);
    }

    private function adjustmentFailed(AcquisitionAdjustmentFailed $failure): JsonResponse
    {
        return response()->json(['error' => ['code' => 'posting_failed', 'message' => $failure->getMessage()]], 500);
    }

    private function requirePermission(Request $request, string $action): void
    {
        abort_unless(in_array('management-aset.'.self::RESOURCE.'.'.$action, $request->attributes->get('coreerp.permissions', []), true), 403);
    }

    private function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('coreerp.tenant_id');
    }

    /**
     * Nilai atribut satu aset, lengkap dengan definisinya.
     *
     * Nilainya disimpan pada kolom bertipe agar PostgreSQL dapat menegakkan tipe dan
     * meng-index-nya, lalu disatukan kembali menjadi satu kunci `nilai` di sini supaya
     * klien tidak perlu tahu kolom mana yang terpakai untuk tipe data mana.
     *
     * @return list<array<string, mixed>>
     */
    private function attributesOf(string $asetId): array
    {
        $baris = AtributAset::query()
            ->join('aset_m_tipe_atribut as tipe', function ($join): void {
                $join->on('tipe.id', '=', 'aset_tr_aset_atribut.tipe_atribut_id')->on('tipe.tenant_id', '=', 'aset_tr_aset_atribut.tenant_id');
            })
            ->where('aset_tr_aset_atribut.aset_id', $asetId)
            ->orderBy('tipe.nama')
            ->toBase()
            ->get(['tipe.id as tipe_atribut_id', 'tipe.kode', 'tipe.nama', 'tipe.data_type', 'tipe.satuan',
                'aset_tr_aset_atribut.nilai_text', 'aset_tr_aset_atribut.nilai_number', 'aset_tr_aset_atribut.nilai_boolean',
                'aset_tr_aset_atribut.nilai_date', 'aset_tr_aset_atribut.tipe_atribut_nilai_id'])
            ->map(fn (object $row): array => [
                'tipe_atribut_id' => $row->tipe_atribut_id,
                'kode' => $row->kode,
                'nama' => $row->nama,
                'data_type' => $row->data_type,
                'satuan' => $row->satuan,
                'tipe_atribut_nilai_id' => $row->tipe_atribut_nilai_id,
                'nilai' => match ($row->data_type) {
                    'decimal', 'integer' => $row->nilai_number === null ? null : (float) $row->nilai_number,
                    'boolean' => $row->nilai_boolean === null ? null : (bool) $row->nilai_boolean,
                    'date' => $row->nilai_date === null ? null : substr((string) $row->nilai_date, 0, 10),
                    default => $row->nilai_text,
                },
            ])
            ->all();

        // `array_values` bukan hiasan: kuncinya harus berurut supaya jawaban JSON tetap
        // sebuah array, bukan objek berkunci angka.
        return array_values($baris);
    }

    /**
     * Menyesuaikan buku aset setelah nilai perolehan atau residu dikoreksi.
     *
     * Hanya dijalankan saat belum ada periode penyusutan sama sekali, sehingga akumulasi
     * masih nol dan nilai buku dapat disamakan langsung dengan nilai perolehan yang baru.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyValueChange(Aset $aset, array $data): void
    {
        $changes = ['updated_at' => now()];
        if (array_key_exists('acquisition_value', $data)) {
            $changes['acquisition_value'] = $data['acquisition_value'];
            $changes['net_book_value'] = $data['acquisition_value'];
        }
        if (array_key_exists('residual_value', $data)) {
            $changes['residual_value'] = $data['residual_value'] ?? 0;
        }
        BukuAset::query()->where('aset_id', $aset->id)->update($changes);
    }

    /**
     * Menghitung ulang tanggal mulai menyusut setelah tanggal aset mulai digunakan
     * dikoreksi. Konvensi tiap buku berbeda, jadi pergeserannya dihitung per buku.
     */
    private function recalculateStartDates(Aset $aset, string $tenantId): void
    {
        $calculator = app(DepreciationCalculator::class);
        $placedInService = (string) ($aset->placed_in_service_on ?? $aset->acquired_on);
        $books = BukuAset::query()
            ->where('aset_id', $aset->id)
            ->toBase()->get(['id', 'convention', 'depreciation_profile_id']);

        foreach ($books as $book) {
            BukuAset::query()->where('id', $book->id)->update([
                'depreciation_start_on' => $calculator->startDate(
                    $placedInService,
                    $book->convention,
                    app(PembuatAset::class)->tahunFiskal($tenantId, (string) $aset->legal_entity_id, $placedInService, $book->depreciation_profile_id, $book->convention),
                )->toDateString(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function present(Aset $aset): array
    {
        return $aset->only(['id', 'kode', 'nama', 'legal_entity_id', 'responsible_org_unit_id', 'group_aset_id', 'kelompok_harta_fiskal_id', 'jenis_aset_id', 'kondisi_aset_id', 'pabrikan_aset_id', 'model_aset_id', 'induk_aset_id', 'lokasi_aset_id', 'financial_dimension_org_unit_id', 'serial_number', 'model_number', 'acquired_on', 'placed_in_service_on', 'acquisition_value', 'currency_code', 'lifecycle_state', 'keterangan']);
    }
}
