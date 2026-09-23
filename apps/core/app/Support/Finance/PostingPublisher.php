<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\FinancePosting;
use App\Models\FinancePostingEvent;
use App\Models\FinancePostingLine;
use App\Models\FinanceReferenceAccount;
use App\Models\FinanceSettlementMode;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\Vendor;
use App\Support\BusinessUnitResolver;
use App\Support\Modules\Contracts\PostingTidakSah;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Penerbit posting finance di Core (area 6). Satu-satunya tempat posting lahir dan dibentuk ulang.
 *
 * Urutan pemeriksaannya disengaja:
 *
 * 1. **Bentuk** — seimbang, satu sisi per baris, presisi, tanggal, vendor, posting asal. Gagal di
 *    sini adalah bug penerbit: dilempar sebagai `PostingTidakSah` dan membatalkan dokumennya (K-22).
 * 2. **Cutover** — entitas legal yang feed-nya tidak aktif, atau tanggal sebelum cutover, menjadi
 *    `manual` dan tidak pernah disajikan (K-16).
 * 3. **Pemetaan** — akun ada dan aktif, dimensi dapat dibentuk dari unit organisasi. Gagal di sini
 *    bukan bug: posting tetap terbit sebagai `held` beserta daftar masalah per baris, dan dokumen
 *    operasionalnya tetap tersimpan (K-18, K-22).
 *
 * Nama dan nomor akun, unit, vendor, serta entitas legal disalin ke payload **pada saat terbit**.
 * Mengganti nama sesudahnya tidak mengubah posting yang sudah terbit.
 */
final class PostingPublisher
{
    public const CONTRACT_VERSION = 1;

    private const MAX_LINES = 5000;

    private const POLA_POSTING_ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/';

    private const POLA_JENIS = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const POLA_WAKTU = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        private readonly MoneyPrecision $presisi,
        private readonly PostingSettings $setelan,
        private readonly BusinessUnitResolver $businessUnits,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    public function publish(array $input): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PenerbitPosting::terbitkan harus dipanggil di dalam transaksi dokumen sumbernya.');
        }

        $ada = $this->existing($input);
        $masukan = $this->normalize($input, $ada?->currency_decimals);
        if ($ada !== null) {
            return $this->hasilYangAda($ada, $masukan);
        }

        $nilai = $this->evaluate($masukan, now()->toIso8601String());

        try {
            // SAVEPOINT di dalam transaksi pemanggil: bentrokan `posting_id` dari permintaan lain
            // tidak boleh membatalkan transaksi dokumennya (PostgreSQL membatalkan seluruhnya).
            $posting = DB::transaction(fn (): FinancePosting => $this->simpan($input, $masukan, $nilai));
        } catch (UniqueConstraintViolationException) {
            $ada = $this->cari($masukan->tenantId, $masukan->postingId)
                ?? throw new RuntimeException('Posting '.$masukan->postingId.' bentrok tetapi tidak ditemukan.');

            return $this->hasilYangAda($ada, $masukan);
        }

        return $this->hasil($posting, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    public function preview(array $input): array
    {
        $ada = $this->existing($input);
        $masukan = $this->normalize($input, $ada?->currency_decimals);
        if ($ada !== null) {
            return $this->hasilYangAda($ada, $masukan);
        }

        $nilai = $this->evaluate($masukan, now()->toIso8601String());

        return [
            'posting_id' => $masukan->postingId,
            'status' => $nilai['status'],
            'problems' => $nilai['status'] === FinancePosting::HELD ? $nilai['problems'] : [],
            'payload' => $nilai['payload'],
            'created' => false,
        ];
    }

    /**
     * @return array{posting_id: string, status: string, external_reference: ?string, reason_code: ?string, reason: ?string, acknowledged_at: ?string, problems: list<array<string, mixed>>}|null
     */
    public function status(string $tenantId, string $postingId): ?array
    {
        $posting = $this->cari($tenantId, $postingId);

        return $posting === null ? null : [
            'posting_id' => $posting->posting_id,
            'status' => $posting->status,
            'external_reference' => $posting->external_reference,
            'reason_code' => $posting->reason_code,
            'reason' => $posting->reason,
            'acknowledged_at' => $posting->acknowledged_at?->toIso8601String(),
            'problems' => $posting->hold_reasons ?? [],
        ];
    }

    /**
     * Membentuk ulang posting `held` setelah pemetaannya diperbaiki (TODO 6.6). `posting_id`, jam
     * terbit, isi jurnal, dan presisinya tetap. Seluruh masukan dibaca ulang terhadap data hari ini:
     * akun, dimensi, vendor, entitas legal, dan cutover.
     */
    public function revalidate(FinancePosting $posting, ?int $userId = null): FinancePosting
    {
        if ($posting->status !== FinancePosting::HELD) {
            return $posting;
        }

        return $this->terapkanUlang($posting, 'revalidated', $userId);
    }

    /**
     * Menandai posting sebagai dibukukan manual oleh pengguna (TODO 7.3.2). Alasannya wajib dan
     * tercatat bersama pelakunya di riwayat posting; `manual_reason` hanya menyimpan bahwa
     * penandanya pengguna, karena kolom itu dijaga CHECK dan dibaca penilaian ulang cutover.
     *
     * Status diperiksa ulang di dalam kunci baris: ack pembaca bisa tiba di antara layar dibuka dan
     * tombol ditekan. Posting `pending` yang sudah pernah disajikan tetap boleh ditandai — pengguna
     * yang memutuskan, dan layar pantau memperingatkan bahwa pembaca mungkin sudah membukukannya.
     * Ack yang tiba sesudahnya dijawab konflik oleh `PostingAcknowledger`.
     *
     * @throws StatusPostingBerubah Status posting tidak lagi mengizinkannya.
     */
    public function markManual(FinancePosting $posting, string $reason, int $userId): FinancePosting
    {
        return DB::transaction(function () use ($posting, $reason, $userId): FinancePosting {
            $terkunci = FinancePosting::query()->lockForUpdate()->findOrFail($posting->id);
            if (! in_array($terkunci->status, FinancePosting::MARKABLE_MANUAL, true)) {
                throw new StatusPostingBerubah(sprintf('Posting %s berstatus %s dan tidak dapat ditandai manual.', $terkunci->posting_id, $terkunci->status));
            }
            $dari = $terkunci->status;
            $terkunci->fill(['status' => FinancePosting::MANUAL, 'manual_reason' => FinancePosting::MANUAL_USER, 'hold_reasons' => null])->save();
            FinancePostingEvent::catat($terkunci, 'marked_manual', $dari, FinancePosting::MANUAL, userId: $userId, data: ['reason' => $reason]);

            return $terkunci;
        });
    }

    /**
     * Menilai ulang posting satu entitas legal setelah feed diaktifkan, dimatikan, atau cutover-nya
     * diubah. Yang disentuh hanya posting yang belum pernah sampai ke pembaca: `held`, `manual`
     * karena cutover atau feed mati, dan `pending` yang belum pernah ditarik atau dikirim. Posting
     * yang sudah disajikan tidak ditarik kembali diam-diam — pembacanya mungkin sudah membukukan.
     *
     * @return int Jumlah posting yang statusnya berubah.
     */
    public function reevaluateCutover(string $tenantId, string $legalEntityId, ?int $userId = null): int
    {
        $setelan = $this->setelan->setting($legalEntityId);
        $aktif = $setelan !== null && $setelan->enabled;
        $cutover = $setelan?->cutover_date?->toDateString();
        $berubah = 0;

        FinancePosting::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where(fn ($query) => $query
                ->where('status', FinancePosting::HELD)
                ->orWhere(fn ($inner) => $inner->where('status', FinancePosting::MANUAL)
                    ->whereIn('manual_reason', [FinancePosting::MANUAL_BEFORE_CUTOVER, FinancePosting::MANUAL_FEED_DISABLED]))
                ->orWhere(fn ($inner) => $inner->where('status', FinancePosting::PENDING)
                    ->where('served_count', 0)
                    ->whereDoesntHave('deliveries')))
            ->chunkById(200, function ($postings) use ($aktif, $cutover, $userId, &$berubah): void {
                foreach ($postings as $posting) {
                    /** @var FinancePosting $posting */
                    $alasan = ! $aktif
                        ? FinancePosting::MANUAL_FEED_DISABLED
                        : ($cutover !== null && $posting->posting_date->toDateString() < $cutover ? FinancePosting::MANUAL_BEFORE_CUTOVER : null);

                    if ($alasan !== null) {
                        if ($posting->status !== FinancePosting::MANUAL || $posting->manual_reason !== $alasan) {
                            $this->jadikanManual($posting, $alasan, $userId);
                            $berubah++;
                        }

                        continue;
                    }

                    if ($posting->status !== FinancePosting::PENDING) {
                        $sebelum = $posting->status;
                        try {
                            $berubah += $this->terapkanUlang($posting, 'cutover_reevaluated', $userId)->status !== $sebelum ? 1 : 0;
                        } catch (PostingTidakSah $kegagalan) {
                            // Setelan entitasnya sudah tersimpan. Posting yang tidak dapat dibentuk
                            // ulang, misalnya karena vendornya sudah diarsipkan, tetap di statusnya
                            // dan tampil di layar pantau; posting lain tetap dinilai ulang.
                            report($kegagalan);
                        }
                    }
                }
            });

        return $berubah;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  int|null  $amountDecimals  Presisi posting yang sudah terbit. Posting lama dibentuk ulang
     *                                    dengan presisi saat ia terbit, bukan presisi mata uang yang
     *                                    berlaku sekarang: perubahan presisi hanya berlaku untuk
     *                                    posting berikutnya (K-20, TODO 5.5.3).
     */
    public function normalize(array $input, ?int $amountDecimals = null): PostingInput
    {
        $tenant = $this->wajib($input, 'tenant_id', 26);
        $postingId = $this->wajib($input, 'posting_id', 120);
        if (preg_match(self::POLA_POSTING_ID, $postingId) !== 1) {
            throw new PostingTidakSah('posting_id hanya boleh huruf, angka, titik, titik dua, garis bawah, dan strip.');
        }
        $jenis = $this->wajib($input, 'posting_type', 80);
        if (preg_match(self::POLA_JENIS, $jenis) !== 1) {
            throw new PostingTidakSah(sprintf('posting_type "%s" harus berbentuk modul.jenis, misalnya asset.acquisition.', $jenis));
        }

        $legalEntityId = $this->wajib($input, 'legal_entity_id', 26);
        $entitas = Organization::query()
            ->where('tenant_id', $tenant)
            ->where('classification', 'legal_entity')
            ->find($legalEntityId);
        if ($entitas === null) {
            throw new PostingTidakSah('legal_entity_id bukan entitas legal milik tenant ini.');
        }
        $kodeEntitas = LegalEntity::query()->where('organization_id', $entitas->id)->value('company_code');

        $mataUang = strtoupper($this->wajib($input, 'currency_code', 3));
        if (preg_match('/^[A-Z]{3}$/', $mataUang) !== 1) {
            throw new PostingTidakSah('currency_code harus kode ISO 4217 tiga huruf.');
        }
        try {
            $desimal = $amountDecimals ?? $this->presisi->amountDecimals($tenant, $mataUang);
        } catch (RuntimeException $kegagalan) {
            throw new PostingTidakSah($kegagalan->getMessage(), 0, $kegagalan);
        }

        $tanggalPosting = $this->tanggal($input, 'posting_date');
        $tanggalDokumen = $this->tanggal($input, 'document_date');
        $terjadi = $this->wajib($input, 'occurred_at', 40);
        if (preg_match(self::POLA_WAKTU, $terjadi) !== 1) {
            throw new PostingTidakSah('occurred_at harus waktu ISO 8601 lengkap dengan offset zona waktu, misalnya 2026-09-28T23:50:00+07:00.');
        }
        $terjadi = Carbon::parse($terjadi)->toIso8601String();

        $sumber = $input['source_document'] ?? null;
        if (! is_array($sumber)) {
            throw new PostingTidakSah('source_document wajib diisi.');
        }
        $dokumen = [
            'module' => $this->wajib($sumber, 'module', 80, 'source_document.module'),
            'type' => $this->wajib($sumber, 'type', 80, 'source_document.type'),
            'number' => $this->teks($sumber, 'number', 80, false, 'source_document.number'),
            'description' => $this->teks($sumber, 'description', 255, false, 'source_document.description'),
            'id' => $this->teks($sumber, 'id', 64, false, 'source_document.id'),
            'url' => $this->tautanDokumen($sumber),
        ];

        $membalik = $this->teks($input, 'reverses_posting_id', 120, false);
        $mengoreksi = $this->teks($input, 'adjusts_posting_id', 120, false);
        if ($membalik !== null && $mengoreksi !== null) {
            throw new PostingTidakSah('Satu posting hanya boleh membalik atau mengoreksi satu posting lain, tidak keduanya.');
        }
        $mode = $this->teks($input, 'settlement_mode', 20, false);
        if ($mode !== null && ! in_array($mode, FinanceSettlementMode::MODES, true)) {
            throw new PostingTidakSah(sprintf('settlement_mode "%s" tidak dikenal.', $mode));
        }
        $asal = $membalik ?? $mengoreksi;
        if ($asal !== null) {
            $induk = $this->cari($tenant, $asal);
            if ($induk === null || $induk->legal_entity_id !== $entitas->id) {
                throw new PostingTidakSah(sprintf('Posting asal %s tidak ditemukan di entitas legal ini.', $asal));
            }
            // K-10: koreksi selalu mewarisi mode posting aslinya, walaupun setelan entitas sudah
            // berganti, supaya koreksi masuk ke akun yang sama dengan jurnal aslinya.
            if ($mode === null) {
                $mode = $induk->settlement_mode;
            } elseif ($induk->settlement_mode !== null && $induk->settlement_mode !== $mode) {
                throw new PostingTidakSah(sprintf('Koreksi atas %s harus memakai mode %s, sama dengan posting aslinya.', $asal, $induk->settlement_mode));
            }
        }

        $vendor = null;
        $vendorId = $this->teks($input, 'vendor_id', 26, false);
        if ($vendorId !== null) {
            $baris = Vendor::query()->with('party:id,name')->where('tenant_id', $tenant)->find($vendorId);
            if ($baris === null || $baris->legal_entity_id !== $entitas->id) {
                throw new PostingTidakSah('vendor_id bukan vendor entitas legal ini.');
            }
            $vendor = ['id' => $baris->id, 'number' => $baris->number, 'name' => (string) $baris->party->name];
        } elseif (($input['requires_vendor'] ?? false) === true) {
            throw new PostingTidakSah('Posting ini wajib membawa vendor: perolehan lewat pembelian dengan mode direct_payable.');
        }

        [$baris, $total] = $this->barisJurnal($input['lines'] ?? null, $desimal);

        $rincian = $input['details'] ?? [];
        if (! is_array($rincian) || ($rincian !== [] && array_is_list($rincian))) {
            throw new PostingTidakSah('details harus objek (array berkunci), bukan daftar.');
        }
        /** @var array<string, mixed> $rincian */
        $hash = hash('sha256', (string) json_encode([
            $jenis, $entitas->id, $mataUang, $tanggalPosting, $tanggalDokumen, $mode, $vendorId, $membalik, $mengoreksi,
            array_map(static fn (array $line): array => [$line['account_id'], $line['debit'], $line['credit'], $line['org_unit_id']], $baris),
        ], JSON_THROW_ON_ERROR));

        return new PostingInput(
            tenantId: $tenant,
            postingId: $postingId,
            postingType: $jenis,
            legalEntityId: $entitas->id,
            legalEntityCode: is_string($kodeEntitas) ? $kodeEntitas : null,
            currencyCode: $mataUang,
            decimals: $desimal,
            postingDate: $tanggalPosting,
            documentDate: $tanggalDokumen,
            occurredAt: $terjadi,
            settlementMode: $mode,
            vendor: $vendor,
            vendorInvoiceReference: $this->teks($input, 'vendor_invoice_reference', 80, false),
            sourceDocument: $dokumen,
            reversesPostingId: $membalik,
            adjustsPostingId: $mengoreksi,
            lines: $baris,
            details: $rincian,
            total: $total,
            hash: $hash,
        );
    }

    /**
     * @return array{status: string, manual_reason: ?string, problems: list<array<string, mixed>>, payload: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function evaluate(PostingInput $masukan, string $terbit): array
    {
        $bentuk = $this->bentuk($masukan, $terbit);
        $setelan = $this->setelan->setting($masukan->legalEntityId);
        $cutover = $setelan?->cutover_date?->toDateString();

        [$status, $alasan] = match (true) {
            $setelan === null || ! $setelan->enabled => [FinancePosting::MANUAL, FinancePosting::MANUAL_FEED_DISABLED],
            $cutover !== null && $masukan->postingDate < $cutover => [FinancePosting::MANUAL, FinancePosting::MANUAL_BEFORE_CUTOVER],
            $bentuk['problems'] !== [] => [FinancePosting::HELD, null],
            default => [FinancePosting::PENDING, null],
        };

        return [
            'status' => $status,
            'manual_reason' => $alasan,
            'problems' => $bentuk['problems'],
            'payload' => $bentuk['payload'],
            'lines' => $bentuk['lines'],
        ];
    }

    /**
     * Payload kontrak, masalah per baris, dan baris untuk tabel `finance_posting_lines`.
     *
     * @return array{payload: array<string, mixed>, problems: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    private function bentuk(PostingInput $masukan, string $terbit): array
    {
        $idAkun = array_values(array_unique(array_filter(array_column($masukan->lines, 'account_id'))));
        $akun = FinanceReferenceAccount::query()
            ->where('tenant_id', $masukan->tenantId)
            ->whereIn('id', $idAkun)
            ->get()
            ->keyBy('id');
        $idUnit = array_values(array_unique(array_filter(array_column($masukan->lines, 'org_unit_id'))));
        $unit = DB::table('organizations')
            ->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
            ->where('organizations.tenant_id', $masukan->tenantId)
            ->whereIn('organizations.id', $idUnit)
            ->get(['organizations.id', 'organizations.name', 'organizations.classification', 'unit.type', 'unit.number'])
            ->keyBy('id');
        $businessUnit = $this->businessUnits->resolve($masukan->tenantId, $idUnit, $masukan->postingDate);

        $masalah = [];
        $barisPayload = [];
        $barisTabel = [];
        foreach ($masukan->lines as $line) {
            $no = $line['line_no'];
            $label = $line['mapping']['label'] ?? 'Baris '.$no;
            $perbaikanPemetaan = ($line['mapping']['fix_url'] ?? null) !== null
                ? ['label' => 'Buka pemetaan akun', 'url' => $line['mapping']['fix_url']]
                : null;

            /** @var FinanceReferenceAccount|null $akunBaris */
            $akunBaris = $line['account_id'] === null ? null : $akun->get($line['account_id']);
            if ($line['account_id'] === null) {
                $masalah[] = $this->masalah($no, 'ACCOUNT_NOT_MAPPED', $label.' belum dipetakan ke akun.', null, $perbaikanPemetaan);
            } elseif ($akunBaris === null) {
                $masalah[] = $this->masalah($no, 'ACCOUNT_UNKNOWN', $label.' menunjuk akun yang tidak ada di daftar akun.', ['type' => 'account', 'id' => $line['account_id'], 'label' => $label], $perbaikanPemetaan);
            } elseif (! $akunBaris->active) {
                $masalah[] = $this->masalah($no, 'ACCOUNT_INACTIVE', sprintf('Akun %s %s nonaktif.', $akunBaris->code, $akunBaris->name), $this->objekAkun($akunBaris), $perbaikanPemetaan ?? $this->perbaikanAkun($akunBaris));
            } elseif ($akunBaris->legal_entity_id !== null && $akunBaris->legal_entity_id !== $masukan->legalEntityId) {
                $masalah[] = $this->masalah($no, 'ACCOUNT_OTHER_LEGAL_ENTITY', sprintf('Akun %s %s khusus entitas legal lain.', $akunBaris->code, $akunBaris->name), $this->objekAkun($akunBaris), $perbaikanPemetaan);
            }

            // K-09: akun neraca hanya membawa business unit; akun laba rugi juga department.
            $perluDepartemen = $akunBaris !== null && $akunBaris->type === FinanceReferenceAccount::PROFIT_LOSS;
            $dimensi = [];
            $kodeBu = null;
            $kodeDepartemen = null;
            $unitBaris = $line['org_unit_id'] === null ? null : $unit->get($line['org_unit_id']);
            if ($line['org_unit_id'] === null) {
                $masalah[] = $this->masalah($no, 'DIMENSION_SOURCE_MISSING', 'Baris ini tidak menyebut unit organisasi, jadi dimensinya tidak dapat dibentuk.', null, null);
            } elseif ($unitBaris === null || $unitBaris->classification !== 'operating_unit') {
                $masalah[] = $this->masalah($no, 'ORG_UNIT_UNKNOWN', 'Unit organisasi baris ini tidak ditemukan.', ['type' => 'organization', 'id' => $line['org_unit_id'], 'label' => $line['org_unit_id']], null);
            } else {
                $namaUnit = (string) $unitBaris->name;
                $bu = $businessUnit[$line['org_unit_id']] ?? null;
                if ($bu === null) {
                    $masalah[] = $this->masalah($no, 'BUSINESS_UNIT_UNRESOLVED', sprintf('%s tidak berada di bawah tepat satu business unit pada hierarki manajemen yang berlaku %s.', $namaUnit, $masukan->postingDate), $this->objekUnit($line['org_unit_id'], $namaUnit), ['label' => 'Buka hierarki organisasi', 'url' => '/settings/organization?section=hierarchies']);
                } elseif ($bu['number'] === null) {
                    $masalah[] = $this->masalah($no, 'BUSINESS_UNIT_NUMBER_MISSING', sprintf('%s belum punya nomor unit.', $bu['name']), $this->objekUnit($bu['id'], $bu['name']), ['label' => 'Buka organisasi', 'url' => '/settings/organization?section=operating-units']);
                } else {
                    $kodeBu = $bu['number'];
                    $dimensi[] = $this->dimensi('BUSINESS_UNIT', 'Business unit', $bu['number'], $bu['name'], $bu['id']);
                }

                if ($perluDepartemen) {
                    if ($unitBaris->type !== 'department') {
                        $masalah[] = $this->masalah($no, 'DEPARTMENT_REQUIRED', sprintf('Akun laba rugi %s membutuhkan department, tetapi %s bukan department.', $akunBaris->code, $namaUnit), $this->objekUnit($line['org_unit_id'], $namaUnit), null);
                    } elseif ($unitBaris->number === null) {
                        $masalah[] = $this->masalah($no, 'DEPARTMENT_NUMBER_MISSING', sprintf('%s belum punya nomor unit.', $namaUnit), $this->objekUnit($line['org_unit_id'], $namaUnit), ['label' => 'Buka organisasi', 'url' => '/settings/organization?section=operating-units']);
                    } else {
                        $kodeDepartemen = (string) $unitBaris->number;
                        $dimensi[] = $this->dimensi('DEPARTMENT', 'Department', $kodeDepartemen, $namaUnit, $line['org_unit_id']);
                    }
                }
            }

            $barisPayload[] = [
                'line_no' => $no,
                'account' => $akunBaris === null ? null : [
                    'external_id' => $akunBaris->external_id,
                    'code' => $akunBaris->code,
                    'name' => $akunBaris->name,
                ],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'financial_dimensions' => $dimensi,
            ];
            $barisTabel[] = [
                'line_no' => $no,
                'account_id' => $akunBaris?->id,
                'account_external_id' => $akunBaris?->external_id,
                'account_code' => $akunBaris?->code,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'org_unit_id' => $line['org_unit_id'],
                'business_unit_code' => $kodeBu,
                'department_code' => $kodeDepartemen,
            ];
        }

        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'posting_id' => $masukan->postingId,
            'posting_type' => $masukan->postingType,
            'settlement_mode' => $masukan->settlementMode,
            'legal_entity' => ['id' => $masukan->legalEntityId, 'code' => $masukan->legalEntityCode],
            'currency' => ['code' => $masukan->currencyCode, 'decimals' => $masukan->decimals],
            'posting_date' => $masukan->postingDate,
            'document_date' => $masukan->documentDate,
            'occurred_at' => $masukan->occurredAt,
            'published_at' => $terbit,
            'source_document' => [
                'module' => $masukan->sourceDocument['module'],
                'type' => $masukan->sourceDocument['type'],
                'number' => $masukan->sourceDocument['number'],
                'description' => $masukan->sourceDocument['description'],
            ],
            'vendor' => $masukan->vendor,
            'vendor_invoice_reference' => $masukan->vendorInvoiceReference,
            'journal_lines' => $barisPayload,
            'totals' => ['debit' => $masukan->total, 'credit' => $masukan->total],
            'reverses_posting_id' => $masukan->reversesPostingId,
            'adjusts_posting_id' => $masukan->adjustsPostingId,
            'details' => $masukan->details,
        ];

        return ['payload' => $payload, 'problems' => $masalah, 'lines' => $barisTabel];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{status: string, manual_reason: ?string, problems: list<array<string, mixed>>, payload: array<string, mixed>, lines: list<array<string, mixed>>}  $nilai
     */
    private function simpan(array $input, PostingInput $masukan, array $nilai): FinancePosting
    {
        $posting = FinancePosting::query()->create([
            'tenant_id' => $masukan->tenantId,
            'legal_entity_id' => $masukan->legalEntityId,
            'posting_id' => $masukan->postingId,
            'posting_type' => $masukan->postingType,
            'contract_version' => self::CONTRACT_VERSION,
            'source_module' => $masukan->sourceDocument['module'],
            'source_type' => $masukan->sourceDocument['type'],
            'source_number' => $masukan->sourceDocument['number'],
            'source_id' => $masukan->sourceDocument['id'],
            'currency_code' => $masukan->currencyCode,
            'currency_decimals' => $masukan->decimals,
            'posting_date' => $masukan->postingDate,
            'document_date' => $masukan->documentDate,
            // Kolomnya disimpan dalam zona aplikasi; payload tetap membawa offset aslinya.
            'occurred_at' => Carbon::parse($masukan->occurredAt)->setTimezone((string) config('app.timezone')),
            'published_at' => Carbon::parse((string) $nilai['payload']['published_at'])->setTimezone((string) config('app.timezone')),
            'settlement_mode' => $masukan->settlementMode,
            'status' => $nilai['status'],
            'manual_reason' => $nilai['manual_reason'],
            'hold_reasons' => $nilai['status'] === FinancePosting::HELD ? $nilai['problems'] : null,
            'vendor_id' => $masukan->vendor['id'] ?? null,
            'reverses_posting_id' => $masukan->reversesPostingId,
            'adjusts_posting_id' => $masukan->adjustsPostingId,
            'total_debit' => $masukan->total,
            'total_credit' => $masukan->total,
            'payload' => $nilai['payload'],
            'input' => $input,
            'input_hash' => $masukan->hash,
        ]);
        $this->simpanBaris($posting, $nilai['lines']);
        FinancePostingEvent::catat($posting, 'published', null, $posting->status, data: ['problems' => count($posting->hold_reasons ?? [])]);

        return $posting;
    }

    private function terapkanUlang(FinancePosting $posting, string $peristiwa, ?int $userId): FinancePosting
    {
        $masukan = $this->normalize($posting->input, $posting->currency_decimals);
        $nilai = $this->evaluate($masukan, (string) ($posting->payload['published_at'] ?? $posting->published_at->toIso8601String()));

        return DB::transaction(function () use ($posting, $nilai, $peristiwa, $userId): FinancePosting {
            $terkunci = FinancePosting::query()->lockForUpdate()->findOrFail($posting->id);
            if (in_array($terkunci->status, [FinancePosting::POSTED, FinancePosting::REJECTED], true) || $this->sudahSampai($terkunci) || $this->markedByUser($terkunci)) {
                return $terkunci;
            }
            $dari = $terkunci->status;
            $terkunci->fill([
                'status' => $nilai['status'],
                'manual_reason' => $nilai['manual_reason'],
                'hold_reasons' => $nilai['status'] === FinancePosting::HELD ? $nilai['problems'] : null,
                'payload' => $nilai['payload'],
            ])->save();
            $terkunci->lines()->delete();
            $this->simpanBaris($terkunci, $nilai['lines']);
            FinancePostingEvent::catat($terkunci, $peristiwa, $dari, $terkunci->status, userId: $userId, data: ['problems' => count($terkunci->hold_reasons ?? [])]);

            return $terkunci;
        });
    }

    private function jadikanManual(FinancePosting $posting, string $alasan, ?int $userId): void
    {
        DB::transaction(function () use ($posting, $alasan, $userId): void {
            $terkunci = FinancePosting::query()->lockForUpdate()->findOrFail($posting->id);
            if (in_array($terkunci->status, [FinancePosting::POSTED, FinancePosting::REJECTED], true) || $this->sudahSampai($terkunci) || $this->markedByUser($terkunci)) {
                return;
            }
            $dari = $terkunci->status;
            $terkunci->fill(['status' => FinancePosting::MANUAL, 'manual_reason' => $alasan, 'hold_reasons' => null])->save();
            FinancePostingEvent::catat($terkunci, 'cutover_reevaluated', $dari, FinancePosting::MANUAL, userId: $userId, data: ['manual_reason' => $alasan]);
        });
    }

    /** @param  list<array<string, mixed>>  $baris */
    private function simpanBaris(FinancePosting $posting, array $baris): void
    {
        $sekarang = now();
        foreach (array_chunk($baris, 500) as $potongan) {
            FinancePostingLine::query()->insert(array_map(static fn (array $line): array => [
                ...$line,
                'id' => strtolower((string) Str::ulid()),
                'tenant_id' => $posting->tenant_id,
                'finance_posting_id' => $posting->id,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ], $potongan));
        }
    }

    /**
     * @return array{0: list<array{line_no: int, account_id: ?string, debit: string, credit: string, description: ?string, org_unit_id: ?string, mapping: ?array{label: string, fix_url: ?string}}>, 1: string}
     */
    private function barisJurnal(mixed $lines, int $desimal): array
    {
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) < 2) {
            throw new PostingTidakSah('lines wajib berisi sedikitnya dua baris jurnal.');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new PostingTidakSah(sprintf('Satu posting paling banyak %d baris jurnal. Ringkas per akun dan dimensi.', self::MAX_LINES));
        }

        $debit = BigDecimal::zero();
        $kredit = BigDecimal::zero();
        $hasil = [];
        foreach ($lines as $indeks => $line) {
            $no = $indeks + 1;
            if (! is_array($line)) {
                throw new PostingTidakSah(sprintf('Baris %d bukan objek.', $no));
            }
            $d = $this->uang($line['debit'] ?? '0', $desimal, sprintf('Baris %d debit', $no));
            $k = $this->uang($line['credit'] ?? '0', $desimal, sprintf('Baris %d kredit', $no));
            if (BigDecimal::of($d)->isZero() === BigDecimal::of($k)->isZero()) {
                throw new PostingTidakSah(sprintf('Baris %d harus berisi debit atau kredit, tepat salah satu.', $no));
            }
            $debit = $debit->plus($d);
            $kredit = $kredit->plus($k);

            $mapping = $line['mapping'] ?? null;
            $hasil[] = [
                'line_no' => $no,
                'account_id' => $this->teks($line, 'account_id', 26, false, sprintf('Baris %d account_id', $no)),
                'debit' => $d,
                'credit' => $k,
                'description' => $this->teks($line, 'description', 255, false, sprintf('Baris %d description', $no)),
                'org_unit_id' => $this->teks($line, 'org_unit_id', 26, false, sprintf('Baris %d org_unit_id', $no)),
                'mapping' => is_array($mapping) ? [
                    'label' => $this->wajib($mapping, 'label', 200, sprintf('Baris %d mapping.label', $no)),
                    'fix_url' => $this->pathInsideApp($this->teks($mapping, 'fix_url', 500, false, sprintf('Baris %d mapping.fix_url', $no)), sprintf('Baris %d mapping.fix_url', $no)),
                ] : null,
            ];
        }

        if (! $debit->isEqualTo($kredit)) {
            throw new PostingTidakSah(sprintf('Jurnal tidak seimbang: debit %s, kredit %s.', $debit, $kredit));
        }

        return [$hasil, MoneyPrecision::round((string) $debit, $desimal)];
    }

    private function uang(mixed $nilai, int $desimal, string $medan): string
    {
        if (is_int($nilai)) {
            $nilai = (string) $nilai;
        }
        if (! is_string($nilai) || preg_match('/^\d+(\.\d+)?$/', trim($nilai)) !== 1) {
            throw new PostingTidakSah($medan.' harus string desimal tanpa tanda dan tanpa pemisah ribuan, misalnya "1500000.00".');
        }

        try {
            $skala = MoneyPrecision::scale($nilai);
        } catch (MathException $kegagalan) {
            throw new PostingTidakSah($medan.' bukan angka desimal.', 0, $kegagalan);
        }
        if ($skala > $desimal) {
            throw new PostingTidakSah(sprintf('%s memakai %d desimal, lebih halus dari presisi mata uang (%d). Bulatkan di sumber lewat PresisiMataUang.', $medan, $skala, $desimal));
        }

        return MoneyPrecision::round($nilai, $desimal);
    }

    /** @param  array<array-key, mixed>  $data */
    private function teks(array $data, string $kunci, int $maks, bool $wajib, ?string $nama = null): ?string
    {
        $nilai = $data[$kunci] ?? null;
        $nama ??= $kunci;
        if ($nilai === null || (is_string($nilai) && trim($nilai) === '')) {
            if ($wajib) {
                throw new PostingTidakSah($nama.' wajib diisi.');
            }

            return null;
        }
        if (! is_string($nilai)) {
            throw new PostingTidakSah($nama.' harus teks.');
        }
        $nilai = trim($nilai);
        if (mb_strlen($nilai) > $maks) {
            throw new PostingTidakSah(sprintf('%s paling panjang %d karakter.', $nama, $maks));
        }

        return $nilai;
    }

    /** @param  array<array-key, mixed>  $data */
    private function wajib(array $data, string $kunci, int $maks, ?string $nama = null): string
    {
        return $this->teks($data, $kunci, $maks, true, $nama) ?? throw new LogicException($kunci.' kosong setelah diperiksa.');
    }

    /** @param  array<string, mixed>  $data */
    private function tanggal(array $data, string $kunci): string
    {
        $nilai = $this->wajib($data, $kunci, 10);
        try {
            $tanggal = Carbon::createFromFormat('!Y-m-d', $nilai);
        } catch (Throwable) {
            $tanggal = null;
        }
        if ($tanggal === null || $tanggal->format('Y-m-d') !== $nilai) {
            throw new PostingTidakSah($kunci.' harus tanggal Y-m-d, misalnya 2026-09-28.');
        }

        return $nilai;
    }

    /**
     * Posting `pending` yang sudah pernah ditarik atau dikirim sudah sampai ke pembaca, dan tidak
     * boleh diubah statusnya diam-diam oleh penilaian ulang.
     */
    private function sudahSampai(FinancePosting $posting): bool
    {
        return $posting->status === FinancePosting::PENDING
            && ($posting->served_count > 0 || $posting->deliveries()->exists());
    }

    /**
     * Tanda manual dari pengguna hanya diubah pengguna. Validasi ulang dan penilaian ulang cutover
     * memilih posting sebelum menguncinya; pengguna yang menandainya di antara keduanya mungkin
     * sudah membukukannya sendiri, dan posting yang kembali `pending` akan dibukukan pembaca untuk
     * kedua kalinya.
     */
    private function markedByUser(FinancePosting $posting): bool
    {
        return $posting->status === FinancePosting::MANUAL && $posting->manual_reason === FinancePosting::MANUAL_USER;
    }

    /**
     * Posting yang sudah terbit dengan `posting_id` masukan ini, dicari sebelum masukannya
     * dinormalkan supaya presisinya dapat dipakai. Masukan yang belum sah tidak menemukan apa pun,
     * lalu ditolak `normalize()` seperti biasa.
     *
     * @param  array<string, mixed>  $input
     */
    private function existing(array $input): ?FinancePosting
    {
        $tenant = $input['tenant_id'] ?? null;
        $postingId = $input['posting_id'] ?? null;

        return is_string($tenant) && is_string($postingId) && $tenant !== '' && $postingId !== ''
            ? $this->cari($tenant, $postingId)
            : null;
    }

    private function cari(string $tenantId, string $postingId): ?FinancePosting
    {
        return FinancePosting::query()->where('tenant_id', $tenantId)->where('posting_id', $postingId)->first();
    }

    /**
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    private function hasilYangAda(FinancePosting $posting, PostingInput $masukan): array
    {
        if (! hash_equals($posting->input_hash, $masukan->hash)) {
            throw new PostingTidakSah(sprintf(
                'Posting %s sudah terbit dengan isi jurnal berbeda. Dokumen yang sudah terbit dikoreksi lewat posting koreksi, bukan diterbitkan ulang.',
                $masukan->postingId,
            ));
        }

        return $this->hasil($posting, false);
    }

    /**
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    private function hasil(FinancePosting $posting, bool $baru): array
    {
        return [
            'posting_id' => $posting->posting_id,
            'status' => $posting->status,
            'problems' => $posting->hold_reasons ?? [],
            'payload' => $posting->payload,
            'created' => $baru,
        ];
    }

    /**
     * @param  array{type: string, id: string, label: string}|null  $objek
     * @param  array{label: string, url: string}|null  $perbaikan
     * @return array<string, mixed>
     */
    private function masalah(int $baris, string $kode, string $pesan, ?array $objek, ?array $perbaikan): array
    {
        return ['line_no' => $baris, 'code' => $kode, 'message' => $pesan, 'object' => $objek, 'fix' => $perbaikan];
    }

    /** @return array{code: string, display_name: string, value_code: string, value_display_name: string, value_id: string} */
    private function dimensi(string $kode, string $nama, string $nilai, string $namaNilai, string $id): array
    {
        return ['code' => $kode, 'display_name' => $nama, 'value_code' => $nilai, 'value_display_name' => $namaNilai, 'value_id' => $id];
    }

    /** @return array{type: string, id: string, label: string} */
    private function objekAkun(FinanceReferenceAccount $akun): array
    {
        return ['type' => 'account', 'id' => $akun->id, 'label' => $akun->code.' '.$akun->name];
    }

    /** @return array{type: string, id: string, label: string} */
    private function objekUnit(string $id, string $nama): array
    {
        return ['type' => 'organization', 'id' => $id, 'label' => $nama];
    }

    /**
     * Alamat layar dokumen sumber, untuk tautan di layar pantau (TODO 7.2). Module yang memberikannya
     * karena hanya module yang tahu alamat layarnya sendiri. Tidak ikut payload pembaca, dan hanya
     * jalur relatif di dalam aplikasi: tautan ke host lain dari data posting akan menjadi pintu
     * pengalihan ke luar CoreERP.
     *
     * @param  array<mixed>  $sumber
     */
    private function tautanDokumen(array $sumber): ?string
    {
        $url = $this->teks($sumber, 'url', 255, false, 'source_document.url');
        if ($url !== null && preg_match('#^/(?!/)[^\s\\\\]*$#', $url) !== 1) {
            throw new PostingTidakSah('source_document.url harus jalur di dalam aplikasi yang diawali satu garis miring, misalnya /management-aset/inventarisasi-aset/penerimaan/01J….');
        }

        return $url;
    }

    /**
     * `mapping.fix_url` menjadi tautan di layar pantau, sama seperti `source_document.url`, jadi
     * dijaga dengan aturan yang sama: jalur di dalam aplikasi, tanpa skema dan tanpa host.
     */
    private function pathInsideApp(?string $url, string $field): ?string
    {
        if ($url !== null && preg_match('#^/(?!/)[^\s\\\\]*$#', $url) !== 1) {
            throw new PostingTidakSah($field.' harus jalur di dalam aplikasi yang diawali satu garis miring, misalnya /m/management-aset/posting-groups/KENDARAAN.');
        }

        return $url;
    }

    /** @return array{label: string, url: string} */
    private function perbaikanAkun(FinanceReferenceAccount $akun): array
    {
        return ['label' => 'Buka daftar akun', 'url' => '/settings/finance-accounts?q='.rawurlencode($akun->code)];
    }
}
