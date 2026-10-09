<?php

declare(strict_types=1);

namespace App\Foundation\FinancePosting\Support;

use App\Foundation\Currency\Support\MoneyPrecision;
use App\Foundation\FinancePosting\Models\FinancePosting;
use App\Foundation\FinancePosting\Models\FinancePostingEvent;
use App\Foundation\FinancePosting\Models\FinancePostingLine;
use App\Foundation\FinancePosting\Models\FinanceReferenceAccount;
use App\Foundation\FinancePosting\Models\FinanceSettlementMode;
use App\Foundation\Vendor\Models\Vendor;
use App\Platform\Modules\Contracts\InvalidPosting;
use App\Platform\Modules\Contracts\PostingAccountResolvers;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Organization\Models\LegalEntity;
use App\Platform\Organization\Models\Organization;
use App\Platform\Organization\Support\BusinessUnitResolver;
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
 *    sini adalah bug penerbit: dilempar sebagai `InvalidPosting` dan membatalkan dokumennya (K-22).
 * 2. **Cutover** — entitas legal yang feed-nya tidak aktif, atau tanggal sebelum cutover, menjadi
 *    `manual` dan tidak pernah disajikan (K-16).
 * 3. **Pemetaan** — akun ada dan aktif, dimensi dapat dibentuk dari unit organisasi. Gagal di sini
 *    bukan bug: posting tetap terbit sebagai `held` beserta daftar masalah per baris, dan dokumen
 *    operasionalnya tetap tersimpan (K-18, K-22).
 *
 * Nama dan nomor akun, unit, vendor, serta entitas legal disalin ke payload **pada saat terbit**.
 * Mengganti nama sesudahnya tidak mengubah posting yang sudah terbit.
 *
 * Posting yang belum sampai ke pembaca dibentuk ulang dari masukan yang tersimpan, dengan satu
 * pengecualian: baris yang membawa `mapping.reference` membaca akunnya dari pemetaan module yang
 * berlaku sekarang (`PostingAccountResolver`), supaya pemetaan yang baru diisi ikut terpakai.
 */
final class PostingPublisher
{
    public const CONTRACT_VERSION = 1;

    private const MAX_LINES = 5000;

    private const POSTING_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/';

    private const POSTING_TYPE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        private readonly MoneyPrecision $precision,
        private readonly PostingSettings $settings,
        private readonly BusinessUnitResolver $businessUnits,
        private readonly PostingAccountResolvers $accountMapper,
        private readonly TenantRunner $runner,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    public function publish(array $input, ?int $decimals = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PostingFeed::publish harus dipanggil di dalam transaksi dokumen sumbernya.');
        }

        $exists = $this->existing($input);
        $postingInput = $this->normalize($input, $exists->currency_decimals ?? $decimals ?? $this->reversalDecimals($input));
        if ($exists !== null) {
            return $this->existingResult($exists, $postingInput);
        }

        $value = $this->reversalSnapshot($this->evaluate($postingInput, now()->toIso8601String()), $input);

        try {
            // SAVEPOINT di dalam transaksi pemanggil: bentrokan `posting_id` dari permintaan lain
            // tidak boleh membatalkan transaksi dokumennya (PostgreSQL membatalkan seluruhnya).
            $posting = DB::transaction(fn (): FinancePosting => $this->store($input, $postingInput, $value));
        } catch (UniqueConstraintViolationException) {
            $exists = $this->find($postingInput->tenantId, $postingInput->postingId)
                ?? throw new RuntimeException('Posting '.$postingInput->postingId.' bentrok tetapi tidak ditemukan.');

            return $this->existingResult($exists, $postingInput);
        }

        return $this->result($posting, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    public function preview(array $input, ?int $decimals = null): array
    {
        $exists = $this->existing($input);
        $postingInput = $this->normalize($input, $exists->currency_decimals ?? $decimals ?? $this->reversalDecimals($input));
        if ($exists !== null) {
            return $this->existingResult($exists, $postingInput);
        }

        $value = $this->reversalSnapshot($this->evaluate($postingInput, now()->toIso8601String()), $input);

        return [
            'posting_id' => $postingInput->postingId,
            'status' => $value['status'],
            'problems' => $value['status'] === FinancePosting::HELD ? $value['problems'] : [],
            'payload' => $value['payload'],
            'created' => false,
        ];
    }

    /**
     * @return array{posting_id: string, status: string, settlement_mode: ?string, external_reference: ?string, reason_code: ?string, reason: ?string, acknowledged_at: ?string, problems: list<array<string, mixed>>}|null
     */
    public function status(string $tenantId, string $postingId): ?array
    {
        $posting = $this->find($tenantId, $postingId);

        return $posting === null ? null : [
            'posting_id' => $posting->posting_id,
            'status' => $posting->status,
            'settlement_mode' => $posting->settlement_mode,
            'posting_date' => $posting->posting_date->toDateString(),
            'manual_reason' => $posting->manual_reason,
            'external_reference' => $posting->external_reference,
            'reason_code' => $posting->reason_code,
            'reason' => $posting->reason,
            'acknowledged_at' => $posting->acknowledged_at?->toIso8601String(),
            'problems' => $posting->hold_reasons ?? [],
        ];
    }

    /**
     * @param array<string,mixed> $cancellation
     * @return list<array<string,mixed>>
     */
    public function reverse(string $tenantId, string $originalPostingId, array $cancellation, bool $preview = false): array
    {
        if (! $preview && DB::transactionLevel() === 0) {
            throw new LogicException('Pembalikan wajib berada dalam transaksi dokumen.');
        }
        $query = FinancePosting::query()->where('tenant_id', $tenantId)
            ->where(function ($query) use ($originalPostingId, $cancellation): void {
                $query->where('posting_id', $originalPostingId);
                if ($cancellation['include_adjustments'] ?? false) {
                    $query->orWhere('adjusts_posting_id', $originalPostingId);
                }
            })->orderBy('posting_id');
        if (! $preview) {
            $query->lockForUpdate();
        }
        $results = [];
        foreach ($query->get() as $original) {
            $input = $original->input;
            $storedLines = $original->lines()->orderBy('line_no')->get([
                'line_no', 'account_id', 'account_external_id', 'account_code', 'debit', 'credit',
                'description', 'org_unit_id', 'business_unit_code', 'department_code',
            ])->toArray();
            $input['lines'] = array_map(static function (array $line): array {
                [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']];

                return $line;
            }, $input['lines']);
            // Akun hasil validasi ulang menjadi akun asal pembalikan, bukan pemetaan hari ini.
            foreach ($storedLines as $i => $line) {
                if ($original->status !== FinancePosting::HELD) {
                    $input['lines'][$i]['account_id'] = $line['account_id'];
                    unset($input['lines'][$i]['mapping']);
                }
            }
            $input['posting_id'] = $cancellation['posting_id'].'-'.substr(hash('sha256', $original->posting_id), 0, 16);
            $input['posting_type'] = $original->posting_type.'_reversal';
            $input['posting_date'] = $cancellation['posting_date'];
            $input['document_date'] = $cancellation['posting_date'];
            $input['occurred_at'] = now()->toIso8601String();
            $input['source_document'] = $cancellation['source_document'];
            $input['reverses_posting_id'] = $original->posting_id;
            unset($input['adjusts_posting_id']);
            $input['details'] = ['original_posting_id' => $original->posting_id];
            $input['reverse_full_journal'] = true;
            $result = $preview ? $this->preview($input, $original->currency_decimals) : $this->publish($input, $original->currency_decimals);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param array{status:string,manual_reason:?string,problems:list<array<string,mixed>>,payload:array<string,mixed>,lines:list<array<string,mixed>>} $value
     * @param array<string,mixed> $input
     * @return array{status:string,manual_reason:?string,problems:list<array<string,mixed>>,payload:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    private function reversalSnapshot(array $value, array $input): array
    {
        if (isset($input['reversal_snapshot'])) {
            foreach (['journal_lines', 'currency', 'totals'] as $key) {
                $value['payload'][$key] = $input['reversal_snapshot'][$key];
            }
            $value['lines'] = $input['reversal_snapshot']['lines'];

            return $value;
        }
        if (! ($input['reverse_full_journal'] ?? false)) {
            return $value;
        }
        $original = $this->find($input['tenant_id'], $input['reverses_posting_id']);
        if (in_array($original->status, [FinancePosting::MANUAL, FinancePosting::REJECTED], true)) {
            $value['status'] = FinancePosting::MANUAL;
            $value['manual_reason'] = $original->status === FinancePosting::REJECTED ? FinancePosting::MANUAL_ORIGINAL_REJECTED : $original->manual_reason;
            $value['problems'] = [];
        } elseif ($original->status === FinancePosting::HELD) {
            $value['status'] = FinancePosting::HELD;
            $value['problems'] = $original->hold_reasons ?? [];

            return $value;
        }
        foreach (['currency', 'legal_entity', 'vendor', 'totals'] as $key) {
            $value['payload'][$key] = $original->payload[$key];
        }
        $swap = static function (array $line): array {
            [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']];

            return $line;
        };
        $value['payload']['journal_lines'] = array_map($swap, $original->payload['journal_lines']);
        $value['lines'] = array_values(array_map($swap, $original->lines()->orderBy('line_no')->get([
            'line_no', 'account_id', 'account_external_id', 'account_code', 'debit', 'credit',
            'description', 'org_unit_id', 'business_unit_code', 'department_code',
        ])->toArray()));

        return $value;
    }

    /** @return array<string,mixed>|null */
    public function journal(string $tenantId, string $postingId): ?array
    {
        $posting = $this->find($tenantId, $postingId);

        return $posting === null ? null : ['status' => $posting->status, 'input_lines' => $posting->input['lines'], 'payload' => $posting->payload,
            'lines' => $posting->lines()->orderBy('line_no')->get(['line_no', 'account_id', 'account_external_id', 'account_code', 'debit', 'credit',
                'description', 'org_unit_id', 'business_unit_code', 'department_code'])->toArray()];
    }

    /**
     * @param array<string,mixed> $input
     */
    private function reversalDecimals(array $input): ?int
    {
        $id = $input['reverses_posting_id'] ?? null;

        return is_string($id) && is_string($input['tenant_id'] ?? null) ? $this->find($input['tenant_id'], $id)?->currency_decimals : null;
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

        return $this->reapply($posting, 'revalidated', $userId);
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
     * @throws PostingStatusChanged Status posting tidak lagi mengizinkannya.
     */
    public function markManual(FinancePosting $posting, string $reason, int $userId): FinancePosting
    {
        return DB::transaction(function () use ($posting, $reason, $userId): FinancePosting {
            $locked = FinancePosting::query()->lockForUpdate()->findOrFail($posting->id);
            if (! in_array($locked->status, FinancePosting::MARKABLE_MANUAL, true)) {
                throw new PostingStatusChanged(sprintf('Posting %s berstatus %s dan tidak dapat ditandai manual.', $locked->posting_id, $locked->status));
            }
            $from = $locked->status;
            $locked->fill(['status' => FinancePosting::MANUAL, 'manual_reason' => FinancePosting::MANUAL_USER, 'hold_reasons' => null])->save();
            FinancePostingEvent::record($locked, 'marked_manual', $from, FinancePosting::MANUAL, userId: $userId, data: ['reason' => $reason]);

            return $locked;
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
        $settings = $this->settings->setting($legalEntityId);
        $active = $settings !== null && $settings->enabled;
        $cutover = $settings?->cutover_date?->toDateString();
        $changed = 0;

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
            ->chunkById(200, function ($postings) use ($active, $cutover, $userId, &$changed): void {
                foreach ($postings as $posting) {
                    /** @var FinancePosting $posting */
                    $reason = ! $active
                        ? FinancePosting::MANUAL_FEED_DISABLED
                        : ($cutover !== null && $posting->posting_date->toDateString() < $cutover ? FinancePosting::MANUAL_BEFORE_CUTOVER : null);

                    if ($reason !== null) {
                        if ($posting->status !== FinancePosting::MANUAL || $posting->manual_reason !== $reason) {
                            $this->makeManual($posting, $reason, $userId);
                            $changed++;
                        }

                        continue;
                    }

                    if ($posting->status !== FinancePosting::PENDING) {
                        $before = $posting->status;
                        try {
                            $changed += $this->reapply($posting, 'cutover_reevaluated', $userId)->status !== $before ? 1 : 0;
                        } catch (InvalidPosting $failure) {
                            // Setelan entitasnya sudah tersimpan. Posting yang tidak dapat dibentuk
                            // ulang, misalnya karena vendornya sudah diarsipkan, tetap di statusnya
                            // dan tampil di layar pantau; posting lain tetap dinilai ulang.
                            report($failure);
                        }
                    }
                }
            });

        return $changed;
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
        $tenant = $this->requiredText($input, 'tenant_id', 26);
        $postingId = $this->requiredText($input, 'posting_id', 120);
        if (preg_match(self::POSTING_ID_PATTERN, $postingId) !== 1) {
            throw new InvalidPosting('posting_id hanya boleh huruf, angka, titik, titik dua, garis bawah, dan strip.');
        }
        $type = $this->requiredText($input, 'posting_type', 80);
        if (preg_match(self::POSTING_TYPE_PATTERN, $type) !== 1) {
            throw new InvalidPosting(sprintf('posting_type "%s" harus berbentuk modul.jenis, misalnya asset.acquisition.', $type));
        }

        $legalEntityId = $this->requiredText($input, 'legal_entity_id', 26);
        $legalEntity = Organization::query()
            ->where('tenant_id', $tenant)
            ->where('classification', 'legal_entity')
            ->find($legalEntityId);
        if ($legalEntity === null) {
            throw new InvalidPosting('legal_entity_id bukan entitas legal milik tenant ini.');
        }
        $legalEntityCode = LegalEntity::query()->where('organization_id', $legalEntity->id)->value('company_code');

        $currency = strtoupper($this->requiredText($input, 'currency_code', 3));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidPosting('currency_code harus kode ISO 4217 tiga huruf.');
        }
        try {
            $decimals = $amountDecimals ?? $this->precision->amountDecimals($tenant, $currency);
        } catch (RuntimeException $failure) {
            throw new InvalidPosting($failure->getMessage(), 0, $failure);
        }

        $postingDate = $this->date($input, 'posting_date');
        $documentDate = $this->date($input, 'document_date');
        $occurred = $this->requiredText($input, 'occurred_at', 40);
        if (preg_match(self::TIMESTAMP_PATTERN, $occurred) !== 1) {
            throw new InvalidPosting('occurred_at harus waktu ISO 8601 lengkap dengan offset zona waktu, misalnya 2026-09-28T23:50:00+07:00.');
        }
        $occurred = Carbon::parse($occurred)->toIso8601String();

        $source = $input['source_document'] ?? null;
        if (! is_array($source)) {
            throw new InvalidPosting('source_document wajib diisi.');
        }
        $document = [
            'module' => $this->requiredText($source, 'module', 80, 'source_document.module'),
            'type' => $this->requiredText($source, 'type', 80, 'source_document.type'),
            'number' => $this->text($source, 'number', 80, false, 'source_document.number'),
            'description' => $this->text($source, 'description', 255, false, 'source_document.description'),
            'id' => $this->text($source, 'id', 64, false, 'source_document.id'),
            'url' => $this->documentLink($source),
        ];

        $reverses = $this->text($input, 'reverses_posting_id', 120, false);
        $corrects = $this->text($input, 'adjusts_posting_id', 120, false);
        if ($reverses !== null && $corrects !== null) {
            throw new InvalidPosting('Satu posting hanya boleh membalik atau mengoreksi satu posting lain, tidak keduanya.');
        }
        $mode = $this->text($input, 'settlement_mode', 20, false);
        if ($mode !== null && ! in_array($mode, FinanceSettlementMode::MODES, true)) {
            throw new InvalidPosting(sprintf('settlement_mode "%s" tidak dikenal.', $mode));
        }
        $origin = $reverses ?? $corrects;
        if ($origin !== null) {
            $parent = $this->find($tenant, $origin);
            if ($parent === null || $parent->legal_entity_id !== $legalEntity->id) {
                throw new InvalidPosting(sprintf('Posting asal %s tidak ditemukan di entitas legal ini.', $origin));
            }
            // K-10: koreksi selalu mewarisi mode posting aslinya, walaupun setelan entitas sudah
            // berganti, supaya koreksi masuk ke akun yang sama dengan jurnal aslinya.
            if ($mode === null) {
                $mode = $parent->settlement_mode;
            } elseif ($parent->settlement_mode !== null && $parent->settlement_mode !== $mode) {
                throw new InvalidPosting(sprintf('Koreksi atas %s harus memakai mode %s, sama dengan posting aslinya.', $origin, $parent->settlement_mode));
            }
        }

        $vendor = null;
        $vendorId = $this->text($input, 'vendor_id', 26, false);
        if ($vendorId !== null) {
            $vendorRow = Vendor::query()->with('party:id,name')->where('tenant_id', $tenant)->find($vendorId);
            if ($vendorRow === null || $vendorRow->legal_entity_id !== $legalEntity->id) {
                throw new InvalidPosting('vendor_id bukan vendor entitas legal ini.');
            }
            $vendor = ['id' => $vendorRow->id, 'number' => $vendorRow->number, 'name' => (string) $vendorRow->party->name];
        } elseif (($input['requires_vendor'] ?? false) === true) {
            throw new InvalidPosting('Posting ini wajib membawa vendor: perolehan lewat pembelian dengan mode direct_payable.');
        }

        [$lines, $total] = $this->journalLines($input['lines'] ?? null, $decimals);

        $details = $input['details'] ?? [];
        if (! is_array($details) || ($details !== [] && array_is_list($details))) {
            throw new InvalidPosting('details harus objek (array berkunci), bukan daftar.');
        }
        /** @var array<string, mixed> $details */
        $hash = hash('sha256', (string) json_encode([
            $type, $legalEntity->id, $currency, $postingDate, $documentDate, $mode, $vendorId, $reverses, $corrects,
            array_map(static fn (array $line): array => [$line['account_id'], $line['debit'], $line['credit'], $line['org_unit_id']], $lines),
        ], JSON_THROW_ON_ERROR));
        if (isset($input['reversal_snapshot'])) {
            $hash = hash('sha256', $hash.json_encode($input['reversal_snapshot'], JSON_THROW_ON_ERROR));
        }

        return new PostingInput(
            tenantId: $tenant,
            postingId: $postingId,
            postingType: $type,
            legalEntityId: $legalEntity->id,
            legalEntityCode: is_string($legalEntityCode) ? $legalEntityCode : null,
            currencyCode: $currency,
            decimals: $decimals,
            postingDate: $postingDate,
            documentDate: $documentDate,
            occurredAt: $occurred,
            settlementMode: $mode,
            vendor: $vendor,
            vendorInvoiceReference: $this->text($input, 'vendor_invoice_reference', 80, false),
            sourceDocument: $document,
            reversesPostingId: $reverses,
            adjustsPostingId: $corrects,
            lines: $lines,
            details: $details,
            total: $total,
            hash: $hash,
        );
    }

    /**
     * @return array{status: string, manual_reason: ?string, problems: list<array<string, mixed>>, payload: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function evaluate(PostingInput $postingInput, string $publishedAt): array
    {
        $shape = $this->shape($postingInput, $publishedAt);
        $origin = $postingInput->reversesPostingId === null ? null : $this->find($postingInput->tenantId, $postingInput->reversesPostingId);
        $settings = $this->settings->setting($postingInput->legalEntityId);
        $cutover = $settings?->cutover_date?->toDateString();

        [$status, $reason] = match (true) {
            $origin?->status === FinancePosting::REJECTED => [FinancePosting::MANUAL, FinancePosting::MANUAL_ORIGINAL_REJECTED],
            $origin?->status === FinancePosting::MANUAL => [FinancePosting::MANUAL, $origin->manual_reason],
            $origin?->status === FinancePosting::HELD => [FinancePosting::HELD, null],
            $settings === null || ! $settings->enabled => [FinancePosting::MANUAL, FinancePosting::MANUAL_FEED_DISABLED],
            $cutover !== null && $postingInput->postingDate < $cutover => [FinancePosting::MANUAL, FinancePosting::MANUAL_BEFORE_CUTOVER],
            $shape['problems'] !== [] => [FinancePosting::HELD, null],
            default => [FinancePosting::PENDING, null],
        };

        return [
            'status' => $status,
            'manual_reason' => $reason,
            'problems' => $shape['problems'],
            'payload' => $shape['payload'],
            'lines' => $shape['lines'],
        ];
    }

    /**
     * Payload kontrak, masalah per baris, dan baris untuk tabel `finance_posting_lines`.
     *
     * @return array{payload: array<string, mixed>, problems: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    private function shape(PostingInput $postingInput, string $publishedAt): array
    {
        $accountIds = array_values(array_unique(array_filter(array_column($postingInput->lines, 'account_id'))));
        $account = FinanceReferenceAccount::query()
            ->where('tenant_id', $postingInput->tenantId)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');
        $unitIds = array_values(array_unique(array_filter(array_column($postingInput->lines, 'org_unit_id'))));
        $unit = DB::table('organizations')
            ->leftJoin('operating_units as unit', 'unit.organization_id', '=', 'organizations.id')
            ->where('organizations.tenant_id', $postingInput->tenantId)
            ->whereIn('organizations.id', $unitIds)
            ->get(['organizations.id', 'organizations.name', 'organizations.classification', 'unit.type', 'unit.number'])
            ->keyBy('id');
        $businessUnit = $this->businessUnits->resolve($postingInput->tenantId, $unitIds, $postingInput->postingDate);

        $issues = [];
        $payloadLines = [];
        $tableLines = [];
        foreach ($postingInput->lines as $line) {
            $no = $line['line_no'];
            $label = $line['mapping']['label'] ?? 'Baris '.$no;
            $mappingFix = ($line['mapping']['fix_url'] ?? null) !== null
                ? ['label' => 'Buka pemetaan akun', 'url' => $line['mapping']['fix_url']]
                : null;

            /** @var FinanceReferenceAccount|null $lineAccount */
            $lineAccount = $line['account_id'] === null ? null : $account->get($line['account_id']);
            if ($line['account_id'] === null) {
                $issues[] = $this->issue($no, 'ACCOUNT_NOT_MAPPED', $label.' belum dipetakan ke akun.', null, $mappingFix);
            } elseif ($lineAccount === null) {
                $issues[] = $this->issue($no, 'ACCOUNT_UNKNOWN', $label.' menunjuk akun yang tidak ada di daftar akun.', ['type' => 'account', 'id' => $line['account_id'], 'label' => $label], $mappingFix);
            } elseif (! $lineAccount->active) {
                $issues[] = $this->issue($no, 'ACCOUNT_INACTIVE', sprintf('Akun %s %s nonaktif.', $lineAccount->code, $lineAccount->name), $this->accountObject($lineAccount), $mappingFix ?? $this->accountFix($lineAccount));
            } elseif ($lineAccount->legal_entity_id !== null && $lineAccount->legal_entity_id !== $postingInput->legalEntityId) {
                $issues[] = $this->issue($no, 'ACCOUNT_OTHER_LEGAL_ENTITY', sprintf('Akun %s %s khusus entitas legal lain.', $lineAccount->code, $lineAccount->name), $this->accountObject($lineAccount), $mappingFix);
            }

            // K-09: akun neraca hanya membawa business unit; akun laba rugi juga department.
            $needsDepartment = $lineAccount !== null && $lineAccount->type === FinanceReferenceAccount::PROFIT_LOSS;
            $dimensions = [];
            $businessUnitCode = null;
            $departmentCode = null;
            $lineUnit = $line['org_unit_id'] === null ? null : $unit->get($line['org_unit_id']);
            if ($line['org_unit_id'] === null) {
                $issues[] = $this->issue($no, 'DIMENSION_SOURCE_MISSING', 'Baris ini tidak menyebut unit organisasi, jadi dimensinya tidak dapat dibentuk.', null, null);
            } elseif ($lineUnit === null || $lineUnit->classification !== 'operating_unit') {
                $issues[] = $this->issue($no, 'ORG_UNIT_UNKNOWN', 'Unit organisasi baris ini tidak ditemukan.', ['type' => 'organization', 'id' => $line['org_unit_id'], 'label' => $line['org_unit_id']], null);
            } else {
                $unitName = (string) $lineUnit->name;
                $bu = $businessUnit[$line['org_unit_id']] ?? null;
                if ($bu === null) {
                    $issues[] = $this->issue($no, 'BUSINESS_UNIT_UNRESOLVED', sprintf('%s tidak berada di bawah tepat satu business unit pada hierarki manajemen yang berlaku %s.', $unitName, $postingInput->postingDate), $this->unitObject($line['org_unit_id'], $unitName), ['label' => 'Buka hierarki organisasi', 'url' => '/settings/organization?section=hierarchies']);
                } elseif ($bu['number'] === null) {
                    $issues[] = $this->issue($no, 'BUSINESS_UNIT_NUMBER_MISSING', sprintf('%s belum punya nomor unit.', $bu['name']), $this->unitObject($bu['id'], $bu['name']), ['label' => 'Buka organisasi', 'url' => '/settings/organization?section=operating-units']);
                } else {
                    $businessUnitCode = $bu['number'];
                    $dimensions[] = $this->dimension('BUSINESS_UNIT', 'Business unit', $bu['number'], $bu['name'], $bu['id']);
                }

                if ($needsDepartment) {
                    if ($lineUnit->type !== 'department') {
                        $issues[] = $this->issue($no, 'DEPARTMENT_REQUIRED', sprintf('Akun laba rugi %s membutuhkan department, tetapi %s bukan department.', $lineAccount->code, $unitName), $this->unitObject($line['org_unit_id'], $unitName), null);
                    } elseif ($lineUnit->number === null) {
                        $issues[] = $this->issue($no, 'DEPARTMENT_NUMBER_MISSING', sprintf('%s belum punya nomor unit.', $unitName), $this->unitObject($line['org_unit_id'], $unitName), ['label' => 'Buka organisasi', 'url' => '/settings/organization?section=operating-units']);
                    } else {
                        $departmentCode = (string) $lineUnit->number;
                        $dimensions[] = $this->dimension('DEPARTMENT', 'Department', $departmentCode, $unitName, $line['org_unit_id']);
                    }
                }
            }

            $payloadLines[] = [
                'line_no' => $no,
                'account' => $lineAccount === null ? null : [
                    'external_id' => $lineAccount->external_id,
                    'code' => $lineAccount->code,
                    'name' => $lineAccount->name,
                ],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'financial_dimensions' => $dimensions,
            ];
            $tableLines[] = [
                'line_no' => $no,
                'account_id' => $lineAccount?->id,
                'account_external_id' => $lineAccount?->external_id,
                'account_code' => $lineAccount?->code,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'org_unit_id' => $line['org_unit_id'],
                'business_unit_code' => $businessUnitCode,
                'department_code' => $departmentCode,
            ];
        }

        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'posting_id' => $postingInput->postingId,
            'posting_type' => $postingInput->postingType,
            'settlement_mode' => $postingInput->settlementMode,
            'legal_entity' => ['id' => $postingInput->legalEntityId, 'code' => $postingInput->legalEntityCode],
            'currency' => ['code' => $postingInput->currencyCode, 'decimals' => $postingInput->decimals],
            'posting_date' => $postingInput->postingDate,
            'document_date' => $postingInput->documentDate,
            'occurred_at' => $postingInput->occurredAt,
            'published_at' => $publishedAt,
            'source_document' => [
                'module' => $postingInput->sourceDocument['module'],
                'type' => $postingInput->sourceDocument['type'],
                'number' => $postingInput->sourceDocument['number'],
                'description' => $postingInput->sourceDocument['description'],
            ],
            'vendor' => $postingInput->vendor,
            'vendor_invoice_reference' => $postingInput->vendorInvoiceReference,
            'journal_lines' => $payloadLines,
            'totals' => ['debit' => $postingInput->total, 'credit' => $postingInput->total],
            'reverses_posting_id' => $postingInput->reversesPostingId,
            'adjusts_posting_id' => $postingInput->adjustsPostingId,
            'details' => $postingInput->details,
        ];

        return ['payload' => $payload, 'problems' => $issues, 'lines' => $tableLines];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{status: string, manual_reason: ?string, problems: list<array<string, mixed>>, payload: array<string, mixed>, lines: list<array<string, mixed>>}  $value
     */
    private function store(array $input, PostingInput $postingInput, array $value): FinancePosting
    {
        $posting = FinancePosting::query()->create([
            'tenant_id' => $postingInput->tenantId,
            'legal_entity_id' => $postingInput->legalEntityId,
            'posting_id' => $postingInput->postingId,
            'posting_type' => $postingInput->postingType,
            'contract_version' => self::CONTRACT_VERSION,
            'source_module' => $postingInput->sourceDocument['module'],
            'source_type' => $postingInput->sourceDocument['type'],
            'source_number' => $postingInput->sourceDocument['number'],
            'source_id' => $postingInput->sourceDocument['id'],
            'currency_code' => $postingInput->currencyCode,
            'currency_decimals' => $postingInput->decimals,
            'posting_date' => $postingInput->postingDate,
            'document_date' => $postingInput->documentDate,
            // Kolomnya disimpan dalam zona aplikasi; payload tetap membawa offset aslinya.
            'occurred_at' => Carbon::parse($postingInput->occurredAt)->setTimezone((string) config('app.timezone')),
            'published_at' => Carbon::parse((string) $value['payload']['published_at'])->setTimezone((string) config('app.timezone')),
            'settlement_mode' => $postingInput->settlementMode,
            'status' => $value['status'],
            'manual_reason' => $value['manual_reason'],
            'hold_reasons' => $value['status'] === FinancePosting::HELD ? $value['problems'] : null,
            'vendor_id' => $postingInput->vendor['id'] ?? null,
            'reverses_posting_id' => $postingInput->reversesPostingId,
            'adjusts_posting_id' => $postingInput->adjustsPostingId,
            'total_debit' => $postingInput->total,
            'total_credit' => $postingInput->total,
            'payload' => $value['payload'],
            'input' => $input,
            'input_hash' => $postingInput->hash,
        ]);
        $this->storeLines($posting, $value['lines']);
        FinancePostingEvent::record($posting, 'published', null, $posting->status, data: ['problems' => count($posting->hold_reasons ?? [])]);

        return $posting;
    }

    private function reapply(FinancePosting $posting, string $event, ?int $userId): FinancePosting
    {
        $input = $this->currentAccounts($posting);
        $postingInput = $this->normalize($input, $posting->currency_decimals);
        $value = $this->reversalSnapshot($this->evaluate($postingInput, (string) ($posting->payload['published_at'] ?? $posting->published_at->toIso8601String())), $input);

        return DB::transaction(function () use ($posting, $input, $postingInput, $value, $event, $userId): FinancePosting {
            $locked = FinancePosting::query()->lockForUpdate()->findOrFail($posting->id);
            if (in_array($locked->status, [FinancePosting::POSTED, FinancePosting::REJECTED], true) || $this->alreadyDelivered($locked) || $this->markedByUser($locked)) {
                return $locked;
            }
            $from = $locked->status;
            $locked->fill([
                'status' => $value['status'],
                'manual_reason' => $value['manual_reason'],
                'hold_reasons' => $value['status'] === FinancePosting::HELD ? $value['problems'] : null,
                'payload' => $value['payload'],
                // Akun yang dibaca ulang menjadi bagian masukan posting ini. Tanpa itu, module yang
                // menerbitkan ulang dokumen yang sama dengan pemetaan terbaru akan ditolak sebagai
                // "isi jurnal berbeda", padahal isinya persis yang sekarang tersimpan.
                'input' => $input,
                'input_hash' => $postingInput->hash,
            ])->save();
            $locked->lines()->delete();
            $this->storeLines($locked, $value['lines']);
            FinancePostingEvent::record($locked, $event, $from, $locked->status, userId: $userId, data: ['problems' => count($locked->hold_reasons ?? [])]);

            return $locked;
        });
    }

    /**
     * Masukan tersimpan dengan akun setiap baris ber-`mapping.reference` dibaca ulang dari module
     * pemilik dokumen sumbernya. Hanya akun: nilai, unit, dan susunan baris tetap.
     *
     * Pemeta yang gagal tidak menghentikan pembentukan ulang. Kegagalannya dilaporkan dan baris itu
     * memakai akun yang tersimpan, karena penilaian ulang cutover memproses banyak posting sekaligus
     * dan satu module yang rusak tidak boleh menahan posting module lain.
     *
     * @return array<string, mixed>
     */
    private function currentAccounts(FinancePosting $posting): array
    {
        $input = $posting->input;
        $module = $input['source_document']['module'] ?? null;
        $mapper = is_string($module) ? $this->accountMapper->for($module) : null;
        $lines = $input['lines'] ?? null;
        if ($mapper === null || ! is_array($lines)) {
            return $input;
        }

        $date = $posting->posting_date->toDateString();
        foreach ($lines as $index => $line) {
            $key = is_array($line) ? ($line['mapping']['reference'] ?? null) : null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            try {
                $input['lines'][$index]['account_id'] = $this->runner->runFor(
                    $posting->tenant_id,
                    fn (): ?string => $mapper->account($posting->tenant_id, $key, $date),
                );
            } catch (Throwable $failure) {
                report($failure);
            }
        }

        return $input;
    }

    private function makeManual(FinancePosting $posting, string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($posting, $reason, $userId): void {
            $locked = FinancePosting::query()->lockForUpdate()->findOrFail($posting->id);
            if (in_array($locked->status, [FinancePosting::POSTED, FinancePosting::REJECTED], true) || $this->alreadyDelivered($locked) || $this->markedByUser($locked)) {
                return;
            }
            $from = $locked->status;
            $locked->fill(['status' => FinancePosting::MANUAL, 'manual_reason' => $reason, 'hold_reasons' => null])->save();
            FinancePostingEvent::record($locked, 'cutover_reevaluated', $from, FinancePosting::MANUAL, userId: $userId, data: ['manual_reason' => $reason]);
        });
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function storeLines(FinancePosting $posting, array $lines): void
    {
        $now = now();
        foreach (array_chunk($lines, 500) as $fragment) {
            FinancePostingLine::query()->insert(array_map(static fn (array $line): array => [
                ...$line,
                'id' => strtolower((string) Str::ulid()),
                'tenant_id' => $posting->tenant_id,
                'finance_posting_id' => $posting->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $fragment));
        }
    }

    /**
     * @return array{0: list<array{line_no: int, account_id: ?string, debit: string, credit: string, description: ?string, org_unit_id: ?string, mapping: ?array{label: string, fix_url: ?string}}>, 1: string}
     */
    private function journalLines(mixed $lines, int $decimals): array
    {
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) < 2) {
            throw new InvalidPosting('lines wajib berisi sedikitnya dua baris jurnal.');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new InvalidPosting(sprintf('Satu posting paling banyak %d baris jurnal. Ringkas per akun dan dimensi.', self::MAX_LINES));
        }

        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        $result = [];
        foreach ($lines as $index => $line) {
            $no = $index + 1;
            if (! is_array($line)) {
                throw new InvalidPosting(sprintf('Baris %d bukan objek.', $no));
            }
            $d = $this->money($line['debit'] ?? '0', $decimals, sprintf('Baris %d debit', $no));
            $k = $this->money($line['credit'] ?? '0', $decimals, sprintf('Baris %d kredit', $no));
            if (BigDecimal::of($d)->isZero() === BigDecimal::of($k)->isZero()) {
                throw new InvalidPosting(sprintf('Baris %d harus berisi debit atau kredit, tepat salah satu.', $no));
            }
            $debit = $debit->plus($d);
            $credit = $credit->plus($k);

            $mapping = $line['mapping'] ?? null;
            if (is_array($mapping)) {
                // Kunci pemetaan hanya dipakai saat posting dibentuk ulang; di sini cukup dipastikan
                // bentuknya, supaya masukan yang tersimpan tidak membawa sesuatu yang tidak terbaca.
                $this->text($mapping, 'reference', 200, false, sprintf('Baris %d mapping.reference', $no));
            }
            $result[] = [
                'line_no' => $no,
                'account_id' => $this->text($line, 'account_id', 26, false, sprintf('Baris %d account_id', $no)),
                'debit' => $d,
                'credit' => $k,
                'description' => $this->text($line, 'description', 255, false, sprintf('Baris %d description', $no)),
                'org_unit_id' => $this->text($line, 'org_unit_id', 26, false, sprintf('Baris %d org_unit_id', $no)),
                'mapping' => is_array($mapping) ? [
                    'label' => $this->requiredText($mapping, 'label', 200, sprintf('Baris %d mapping.label', $no)),
                    'fix_url' => $this->pathInsideApp($this->text($mapping, 'fix_url', 500, false, sprintf('Baris %d mapping.fix_url', $no)), sprintf('Baris %d mapping.fix_url', $no)),
                ] : null,
            ];
        }

        if (! $debit->isEqualTo($credit)) {
            throw new InvalidPosting(sprintf('Jurnal tidak seimbang: debit %s, kredit %s.', $debit, $credit));
        }

        return [$result, MoneyPrecision::round((string) $debit, $decimals)];
    }

    private function money(mixed $value, int $decimals, string $field): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || preg_match('/^\d+(\.\d+)?$/', trim($value)) !== 1) {
            throw new InvalidPosting($field.' harus string desimal tanpa tanda dan tanpa pemisah ribuan, misalnya "1500000.00".');
        }

        try {
            $scale = MoneyPrecision::scale($value);
        } catch (MathException $failure) {
            throw new InvalidPosting($field.' bukan angka desimal.', 0, $failure);
        }
        if ($scale > $decimals) {
            throw new InvalidPosting(sprintf('%s memakai %d desimal, lebih halus dari presisi mata uang (%d). Bulatkan di sumber lewat CurrencyRounding.', $field, $scale, $decimals));
        }

        return MoneyPrecision::round($value, $decimals);
    }

    /** @param  array<array-key, mixed>  $data */
    private function text(array $data, string $key, int $max, bool $required, ?string $name = null): ?string
    {
        $value = $data[$key] ?? null;
        $name ??= $key;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw new InvalidPosting($name.' wajib diisi.');
            }

            return null;
        }
        if (! is_string($value)) {
            throw new InvalidPosting($name.' harus teks.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidPosting(sprintf('%s paling panjang %d karakter.', $name, $max));
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $data */
    private function requiredText(array $data, string $key, int $max, ?string $name = null): string
    {
        return $this->text($data, $key, $max, true, $name) ?? throw new LogicException($key.' kosong setelah diperiksa.');
    }

    /** @param  array<string, mixed>  $data */
    private function date(array $data, string $key): string
    {
        $value = $this->requiredText($data, $key, 10);
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new InvalidPosting($key.' harus tanggal Y-m-d, misalnya 2026-09-28.');
        }

        return $value;
    }

    /**
     * Posting `pending` yang sudah pernah ditarik atau dikirim sudah sampai ke pembaca, dan tidak
     * boleh diubah statusnya diam-diam oleh penilaian ulang.
     */
    private function alreadyDelivered(FinancePosting $posting): bool
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
            ? $this->find($tenant, $postingId)
            : null;
    }

    private function find(string $tenantId, string $postingId): ?FinancePosting
    {
        return FinancePosting::query()->where('tenant_id', $tenantId)->where('posting_id', $postingId)->first();
    }

    /**
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    private function existingResult(FinancePosting $posting, PostingInput $postingInput): array
    {
        if (! hash_equals($posting->input_hash, $postingInput->hash)) {
            throw new InvalidPosting(sprintf(
                'Posting %s sudah terbit dengan isi jurnal berbeda. Dokumen yang sudah terbit dikoreksi lewat posting koreksi, bukan diterbitkan ulang.',
                $postingInput->postingId,
            ));
        }

        return $this->result($posting, false);
    }

    /**
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
     */
    private function result(FinancePosting $posting, bool $isNew): array
    {
        return [
            'posting_id' => $posting->posting_id,
            'status' => $posting->status,
            'problems' => $posting->hold_reasons ?? [],
            'payload' => $posting->payload,
            'created' => $isNew,
        ];
    }

    /**
     * @param  array{type: string, id: string, label: string}|null  $object
     * @param  array{label: string, url: string}|null  $fix
     * @return array<string, mixed>
     */
    private function issue(int $lineNo, string $code, string $message, ?array $object, ?array $fix): array
    {
        return ['line_no' => $lineNo, 'code' => $code, 'message' => $message, 'object' => $object, 'fix' => $fix];
    }

    /** @return array{code: string, display_name: string, value_code: string, value_display_name: string, value_id: string} */
    private function dimension(string $code, string $name, string $value, string $valueName, string $id): array
    {
        return ['code' => $code, 'display_name' => $name, 'value_code' => $value, 'value_display_name' => $valueName, 'value_id' => $id];
    }

    /** @return array{type: string, id: string, label: string} */
    private function accountObject(FinanceReferenceAccount $account): array
    {
        return ['type' => 'account', 'id' => $account->id, 'label' => $account->code.' '.$account->name];
    }

    /** @return array{type: string, id: string, label: string} */
    private function unitObject(string $id, string $name): array
    {
        return ['type' => 'organization', 'id' => $id, 'label' => $name];
    }

    /**
     * Alamat layar dokumen sumber, untuk tautan di layar pantau (TODO 7.2). Module yang memberikannya
     * karena hanya module yang tahu alamat layarnya sendiri. Tidak ikut payload pembaca, dan hanya
     * jalur relatif di dalam aplikasi: tautan ke host lain dari data posting akan menjadi pintu
     * pengalihan ke luar CoreERP.
     *
     * @param  array<mixed>  $source
     */
    private function documentLink(array $source): ?string
    {
        $url = $this->text($source, 'url', 255, false, 'source_document.url');
        if ($url !== null && preg_match('#^/(?!/)[^\s\\\\]*$#', $url) !== 1) {
            throw new InvalidPosting('source_document.url harus jalur di dalam aplikasi yang diawali satu garis miring, misalnya /management-aset/inventarisasi-aset/penerimaan/01J….');
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
            throw new InvalidPosting($field.' harus jalur di dalam aplikasi yang diawali satu garis miring, misalnya /m/management-aset/posting-groups/KENDARAAN.');
        }

        return $url;
    }

    /** @return array{label: string, url: string} */
    private function accountFix(FinanceReferenceAccount $account): array
    {
        return ['label' => 'Buka daftar akun', 'url' => '/settings/finance-accounts?q='.rawurlencode($account->code)];
    }
}
