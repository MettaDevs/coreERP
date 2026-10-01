<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\CurrencyRounding;
use App\Platform\Modules\Contracts\InvalidPosting;
use App\Platform\Modules\Contracts\PostingFeed;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;
use Modules\Apperp\ManagementAset\Support\StatusAset;
use RuntimeException;
use stdClass;

/**
 * Jurnal dokumen penyesuaian nilai aset: `asset.write_down` untuk penurunan nilai, `asset.appreciation`
 * untuk kenaikan nilai. Padanan posting type *Write-Down* dan *Appreciation* Business Central, dan
 * transaksi *Write-down adjustment* / *Revaluation* Dynamics 365 F&O.
 *
 * Satu posting per dokumen, terbit di dalam transaksi posting dokumennya, diringkas per group aset dan unit
 * seperti "Post penyusutan" (K-14):
 *
 *     Penurunan nilai                                          Kenaikan nilai
 *     Dr beban penurunan nilai      unit pengguna              Dr kenaikan nilai aset       dimensi keuangan aset
 *        Cr akumulasi penurunan nilai  dimensi keuangan aset      Cr lawan kenaikan nilai     unit pengguna
 *
 * Akun neraca yang membawa nilai aset memakai dimensi keuangan aset, sama dengan jurnal perolehan dan
 * dengan pelepasan yang kelak membaliknya; beban dan lawannya memakai unit pengguna, department yang juga
 * menanggung penyusutannya.
 *
 * **Hanya buku yang di-post ke finance** — buku yang dulu mem-post perolehan aset itu (K-26) — yang
 * menjurnal. Penyesuaian di buku lain, misalnya buku fiskal, hanya mengubah nilai buku di register.
 *
 * @phpstan-type HasilTerbit array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
 */
final class ValueAdjustmentPosting
{
    public const WRITE_DOWN_TYPE = 'asset.write_down';

    public const APPRECIATION_TYPE = 'asset.appreciation';

    public const SOURCE_TYPE = 'penyesuaian-nilai-aset';

    public function __construct(
        private readonly PostingFeed $publisher,
        private readonly CurrencyRounding $precision,
        private readonly AssetPostingAccounts $accounts,
        private readonly PembuatAset $assets,
        private readonly BookPeriods $periods,
    ) {}

    public static function postingId(string $adjustmentId, string $kind): string
    {
        return ($kind === AssetValueAdjustment::WRITE_DOWN ? 'AST-WDN-' : 'AST-APR-').$adjustmentId;
    }

    /**
     * Baris dokumen beserta asetnya dan buku aset untuk buku header, urut nomor baris. Dengan `$lock`,
     * buku asetnya dikunci: posting mengubah nilai bukunya.
     *
     * @return list<stdClass> `line_number`, `aset_id`, `nilai`, `aset` (baris aset), `book` (baris buku aset atau `null`)
     */
    public function rows(AssetValueAdjustment $header, bool $lock = false): array
    {
        $lines = AssetValueAdjustmentLine::query()
            ->where('penyesuaian_nilai_aset_id', $header->id)
            ->orderBy('line_number')
            ->toBase()
            ->get(['id', 'line_number', 'aset_id', 'nilai', 'keterangan']);
        $asetIds = $lines->pluck('aset_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all();
        $aset = Aset::withTrashed()->whereIn('id', $asetIds)->toBase()
            ->get(['id', 'kode', 'nama', 'group_aset_id', 'legal_entity_id', 'responsible_org_unit_id', 'financial_dimension_org_unit_id', 'currency_code', 'lifecycle_state'])
            ->keyBy('id');
        $books = BukuAset::query()->whereIn('aset_id', $asetIds)->where('buku_id', $header->buku_id)->orderBy('id');
        if ($lock) {
            $books->lockForUpdate();
        }
        $books = $books->toBase()->get()->keyBy('aset_id');

        return array_values($lines->map(static function (stdClass $line) use ($aset, $books): stdClass {
            $line->aset = $aset[$line->aset_id] ?? null;
            $line->book = $books[$line->aset_id] ?? null;

            return $line;
        })->all());
    }

    /**
     * Yang menahan posting, berkunci field; kosong berarti boleh. Dipakai posting dan pratinjau, supaya
     * keduanya tidak pernah menjawab berbeda. Masalah mata uang berkunci `currency_code`: dengan masalah
     * itu jurnalnya tidak dapat disusun sama sekali. Masalah pemetaan akun tidak di sini: postingnya tetap terbit
     * sebagai `held` dan nilai bukunya tetap berubah (K-18).
     *
     * @param  list<stdClass>  $rows
     * @return array<string, string>
     */
    public function blockers(AssetValueAdjustment $header, array $rows): array
    {
        if ($rows === []) {
            return ['details' => 'Tambahkan sedikitnya satu aset sebelum memposting.'];
        }
        $tanggal = $header->tanggal->toDateString();
        $kodeBuku = BukuPenyusutan::withTrashed()->whereKey($header->buku_id)->value('kode') ?? $header->buku_id;
        $mataUang = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) ($row->aset->currency_code ?? ''), $rows)));
        if (count($mataUang) > 1) {
            return ['currency_code' => sprintf('Aset di dokumen ini memakai lebih dari satu mata uang (%s). Satu penyesuaian hanya untuk satu mata uang.', implode(', ', $mataUang))];
        }
        try {
            $desimal = $this->precision->amountDecimals((string) $header->tenant_id, $mataUang[0]);
        } catch (RuntimeException $kegagalan) {
            return ['currency_code' => $kegagalan->getMessage()];
        }

        foreach ($rows as $row) {
            if (BigDecimal::of((string) $row->nilai)->strippedOfTrailingZeros()->getScale() > $desimal) {
                return ['currency_code' => sprintf(
                    'Nilai penyesuaian aset %s lebih halus dari presisi %s (%d desimal), jadi jurnalnya tidak akan sama persis dengan register.',
                    $row->aset->kode ?? $row->aset_id,
                    $mataUang[0],
                    $desimal,
                )];
            }
        }

        $masalah = [];
        foreach ($rows as $indeks => $row) {
            $kode = (string) ($row->aset->kode ?? $row->aset_id);
            $kunci = 'details.'.$indeks.'.aset_id';
            $nilai = BigDecimal::of((string) $row->nilai);
            $pesan = match (true) {
                $row->aset === null || StatusAset::sudahDilepas($row->aset->lifecycle_state) || ($row->book->status ?? null) === 'closed' => sprintf('Aset %s sudah dilepas, jadi nilainya tidak dapat disesuaikan lagi.', $kode),
                $row->aset->legal_entity_id !== $header->legal_entity_id => sprintf('Aset %s berada di entitas legal lain.', $kode),
                $row->book === null => sprintf('Aset %s tidak punya buku %s.', $kode, $kodeBuku),
                $header->jenis === AssetValueAdjustment::WRITE_DOWN && $nilai->isGreaterThan((string) $row->book->net_book_value) => sprintf(
                    'Penurunan nilai aset %s (%s) melebihi nilai bukunya di buku %s (%s). Nilai buku tidak boleh menjadi negatif.',
                    $kode,
                    $nilai->toScale(2),
                    $kodeBuku,
                    BigDecimal::of((string) $row->book->net_book_value)->toScale(2),
                ),
                default => $this->periods->problem((string) $row->book->id, $tanggal, $kode),
            };
            if ($pesan !== null) {
                $masalah[$kunci] = $pesan;
            }
        }

        return $masalah;
    }

    /**
     * Pratinjau jurnal penyesuaian (K-22) tanpa menyimpan apa pun.
     *
     * @param  list<stdClass>  $rows
     * @return array{note: ?string, posting: HasilTerbit|null}
     *
     * @throws ValueAdjustmentPostingFailed
     */
    public function preview(AssetValueAdjustment $header, array $rows): array
    {
        $susunan = $this->susun($header, $rows);

        return ['note' => $susunan['note'], 'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->preview($susunan['input']))];
    }

    /**
     * Menerbitkan jurnal penyesuaian **di dalam transaksi posting dokumennya**, yang sudah mengunci buku
     * asetnya. `$rows` adalah keadaan sebelum nilai bukunya diubah.
     *
     * @param  list<stdClass>  $rows
     * @return array{note: ?string, posting: HasilTerbit|null}
     *
     * @throws ValueAdjustmentPostingFailed
     */
    public function publish(AssetValueAdjustment $header, array $rows): array
    {
        $susunan = $this->susun($header, $rows);

        return ['note' => $susunan['note'], 'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->publish($susunan['input']))];
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array{note: ?string, input: array<string, mixed>|null}
     */
    private function susun(AssetValueAdjustment $header, array $rows): array
    {
        $tanggal = $header->tanggal->toDateString();
        $turun = $header->jenis === AssetValueAdjustment::WRITE_DOWN;
        $dijurnal = array_values(array_filter($rows, fn (stdClass $row): bool => $row->aset !== null && $row->book !== null
            && $this->assets->bukuDiPostId((string) $row->aset->group_aset_id) === (string) $header->buku_id));
        if ($dijurnal === []) {
            $buku = BukuPenyusutan::withTrashed()->whereKey($header->buku_id)->value('nama') ?? $header->buku_id;

            return ['note' => sprintf('Buku %s tidak membawa aset di dokumen ini ke aplikasi finance, jadi penyesuaian ini hanya mengubah nilai buku di register aset.', $buku), 'input' => null];
        }

        $tenant = (string) $header->tenant_id;
        $mataUang = (string) $dijurnal[0]->aset->currency_code;
        $desimal = $this->precision->amountDecimals($tenant, $mataUang);
        if ($desimal < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }
        $grup = GroupAset::withTrashed()
            ->whereIn('id', array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->aset->group_aset_id, $dijurnal))))
            ->toBase()->get(['id', 'kode', 'nama'])->keyBy('id');
        $pengguna = $this->usageUnits(array_map(static fn (stdClass $row): string => (string) $row->aset_id, $dijurnal), $tanggal);

        // Kolom yang dibebani dan dikreditkan, dengan sisi mana yang memakai dimensi keuangan aset.
        [$kolomDebit, $kolomKredit] = $turun
            ? ['write_down_expense_account_id', 'write_down_account_id']
            : ['appreciation_account_id', 'appreciation_offset_account_id'];
        $debit = [];
        $kredit = [];
        $rincian = [];
        foreach ($dijurnal as $row) {
            $group = (string) $row->aset->group_aset_id;
            $neraca = (string) ($row->aset->financial_dimension_org_unit_id ?? $row->aset->responsible_org_unit_id);
            $unitPengguna = $pengguna[(string) $row->aset_id] ?? (string) $row->aset->responsible_org_unit_id;
            [$unitDebit, $unitKredit] = $turun ? [$unitPengguna, $neraca] : [$neraca, $unitPengguna];
            $nilai = BigDecimal::of((string) $row->nilai);

            $debit[$group.'|'.$unitDebit] ??= ['group' => $group, 'unit' => $unitDebit, 'amount' => BigDecimal::zero()];
            $debit[$group.'|'.$unitDebit]['amount'] = $debit[$group.'|'.$unitDebit]['amount']->plus($nilai);
            $kredit[$group.'|'.$unitKredit] ??= ['group' => $group, 'unit' => $unitKredit, 'amount' => BigDecimal::zero()];
            $kredit[$group.'|'.$unitKredit]['amount'] = $kredit[$group.'|'.$unitKredit]['amount']->plus($nilai);

            $sebelum = BigDecimal::of((string) $row->book->net_book_value);
            $rincian[] = [
                'asset_code' => (string) $row->aset->kode,
                'asset_group' => $grup[$group]->kode ?? null,
                'book' => (string) $row->book->book_code,
                'adjustment_amount' => (string) $nilai->toScale($desimal),
                'net_book_value_before' => (string) $sebelum->toScale($desimal),
                'net_book_value_after' => (string) ($turun ? $sebelum->minus($nilai) : $sebelum->plus($nilai))->toScale($desimal),
            ];
        }
        // Urutan baris tetap untuk masukan yang sama: posting yang diterbitkan ulang dibandingkan isinya.
        $urut = static fn (array $a, array $b): int => [$grup[$a['group']]->kode ?? $a['group'], $a['unit']] <=> [$grup[$b['group']]->kode ?? $b['group'], $b['unit']];
        uasort($debit, $urut);
        uasort($kredit, $urut);

        $label = Carbon::parse($tanggal)->format('d/m/Y');
        $jenis = AssetValueAdjustment::KINDS[$header->jenis];
        $baris = [];
        foreach ([[$debit, $kolomDebit, true], [$kredit, $kolomKredit, false]] as [$bagian, $kolom, $sisiDebit]) {
            foreach ($bagian as $potong) {
                $jumlah = (string) $potong['amount']->toScale($desimal);
                $baris[] = $this->accounts->line(
                    $potong['group'],
                    (string) ($grup[$potong['group']]->kode ?? $potong['group']),
                    $kolom,
                    $sisiDebit ? $jumlah : '0',
                    $sisiDebit ? '0' : $jumlah,
                    sprintf('%s aset %s · %s · %s', $jenis, $label, $header->kode, $grup[$potong['group']]->nama ?? $potong['group']),
                    $potong['unit'],
                    $tanggal,
                );
            }
        }

        return ['note' => null, 'input' => [
            'tenant_id' => $tenant,
            'posting_id' => self::postingId((string) $header->id, (string) $header->jenis),
            'posting_type' => $turun ? self::WRITE_DOWN_TYPE : self::APPRECIATION_TYPE,
            'legal_entity_id' => (string) $header->legal_entity_id,
            'currency_code' => $mataUang,
            'posting_date' => $tanggal,
            'document_date' => $tanggal,
            'occurred_at' => now()->toIso8601String(),
            'source_document' => [
                'module' => AcquisitionPosting::MODULE,
                'type' => self::SOURCE_TYPE,
                'number' => (string) $header->kode,
                'description' => mb_substr($jenis.' aset '.$header->kode.': '.trim((string) $header->keterangan), 0, 255),
                'id' => (string) $header->id,
                'url' => '/management-aset/'.self::SOURCE_TYPE.'/'.$header->id,
            ],
            'lines' => $baris,
            'details' => ['assets' => $rincian, 'reason' => (string) $header->keterangan],
        ]];
    }

    /**
     * Unit pengguna tiap aset pada tanggal penyesuaian, dari penempatan terakhir sampai tanggal itu.
     *
     * @param  list<string>  $asetIds
     * @return array<string, string>
     */
    private function usageUnits(array $asetIds, string $tanggal): array
    {
        $units = [];
        foreach (PenempatanAset::query()->whereIn('aset_id', $asetIds)->whereDate('effective_on', '<=', $tanggal)
            ->orderByDesc('effective_on')->orderByDesc('id')->toBase()->get(['aset_id', 'usage_org_unit_id']) as $penempatan) {
            if ($penempatan->usage_org_unit_id !== null) {
                $units[(string) $penempatan->aset_id] ??= (string) $penempatan->usage_org_unit_id;
            }
        }

        return $units;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     *
     * @throws ValueAdjustmentPostingFailed
     */
    private function atauGagal(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (InvalidPosting $kegagalan) {
            report($kegagalan);

            throw new ValueAdjustmentPostingFailed($kegagalan);
        }
    }
}
