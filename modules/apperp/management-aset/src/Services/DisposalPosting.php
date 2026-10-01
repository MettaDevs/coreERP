<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Platform\Modules\Contracts\CurrencyRounding;
use App\Platform\Modules\Contracts\InvalidPosting;
use App\Platform\Modules\Contracts\PostingFeed;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\PenempatanAset;
use RuntimeException;
use stdClass;

/**
 * Jurnal pelepasan aset: `asset.disposal_sale` untuk penjualan, `asset.disposal_scrap` untuk pemusnahan.
 *
 * Padanannya posting type *Disposal* Business Central (`Calculate Disposal`, `FA Get G/L Account No.`)
 * dengan metode *Net*, dan transaksi *Disposal - sale* / *Disposal - scrap* Dynamics 365 F&O. Dua jenis
 * posting, seperti F&O, supaya aplikasi finance dapat memetakan penjualan dan pemusnahan berbeda.
 *
 * **Bentuknya**, bertanggal tanggal dokumen pelepasan, dari saldo buku yang di-post ke finance:
 *
 *     Dr akumulasi penyusutan            akumulasi buku itu                 unit pengguna
 *     Dr akumulasi penurunan nilai       penurunan nilai yang tercatat       dimensi keuangan aset
 *        Cr harga perolehan              harga perolehan buku itu            dimensi keuangan aset
 *        Cr kenaikan nilai aset          kenaikan nilai yang tercatat        dimensi keuangan aset
 *     Dr hasil penjualan aset            nilai penjualan (penjualan saja)    unit pengguna
 *        Cr laba pelepasan               hasil − nilai buku, bila positif    unit pengguna
 *     Dr rugi pelepasan                  nilai buku − hasil, bila positif    unit pengguna
 *
 * Penurunan dan kenaikan nilai dibalik bersama harga perolehan ("Write-Down Acc. on Disposal" BC) karena
 * keduanya bagian nilai buku. Baris yang membalik saldo memakai dimensi tempat saldo itu dulu dicatat:
 * akumulasi penyusutan di unit pengguna seperti "Post penyusutan", sisanya di dimensi keuangan aset seperti
 * jurnal perolehan. Laba/rugi dan hasil penjualan memakai unit pengguna, yang department.
 *
 * **Hanya buku yang di-post ke finance** — buku yang dulu mem-post perolehan aset itu (K-26) — yang
 * menjurnal. Buku lain tetap ditutup di register, tanpa jurnal.
 *
 * `posting_id`-nya diturunkan dari id aset: satu aset hanya dilepas sekali, jadi pratinjau dan penerbitan
 * menyebut posting yang sama, dan percobaan ulang tidak pernah menerbitkan yang kedua.
 *
 * @phpstan-type HasilTerbit array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
 * @phpstan-type Rincian array{book: string, acquisition_value: string, accumulated_depreciation: string, write_down_amount: string, appreciation_amount: string, net_book_value: string, proceeds: string, gain_loss: string}
 */
final class DisposalPosting
{
    public const SALE_TYPE = 'asset.disposal_sale';

    public const SCRAP_TYPE = 'asset.disposal_scrap';

    public const SALE = 'penjualan-aset';

    public const SCRAP = 'pemusnahan-aset';

    public const SCRAP_HAS_NO_PROCEEDS = 'Pemusnahan tidak membawa hasil penjualan. Bila aset dijual sebagai rongsokan, catat sebagai penjualan aset.';

    private const TANPA_BUKU = 'Group aset ini tidak punya buku yang di-post ke aplikasi finance, jadi pelepasannya hanya menutup buku di register aset.';

    public function __construct(
        private readonly PostingFeed $publisher,
        private readonly CurrencyRounding $precision,
        private readonly AssetPostingAccounts $accounts,
        private readonly PembuatAset $assets,
        private readonly BookPeriods $periods,
    ) {}

    public static function postingId(string $asetId): string
    {
        return 'AST-DSP-'.$asetId;
    }

    /**
     * Yang menahan posting pelepasan, berkunci field; kosong berarti boleh. Dipakai posting dan pratinjau,
     * supaya keduanya tidak pernah menjawab berbeda. Masalah pemetaan akun tidak di sini: postingnya tetap
     * terbit sebagai `held` dan asetnya tetap dilepas (K-18).
     *
     * @return array<string, string>
     */
    public function blockers(Aset $aset, string $type, string $tanggal, ?string $proceeds): array
    {
        if ($type === self::SCRAP && $proceeds !== null && ! BigDecimal::of($proceeds)->isZero()) {
            return ['nilai' => self::SCRAP_HAS_NO_PROCEEDS];
        }

        foreach ($this->activeBooks((string) $aset->id) as $book) {
            $masalah = $this->periods->problem((string) $book->id, $tanggal, (string) $aset->kode);
            if ($masalah !== null) {
                return ['tanggal' => $masalah];
            }
        }

        $book = $this->postedBook($aset);
        if ($book === null) {
            return [];
        }
        try {
            $desimal = $this->precision->amountDecimals((string) $aset->tenant_id, (string) $aset->currency_code);
        } catch (RuntimeException $kegagalan) {
            return ['nilai' => $kegagalan->getMessage()];
        }
        foreach ([$book->acquisition_value, $book->accumulated_depreciation, $book->write_down_amount, $book->appreciation_amount, $proceeds ?? '0'] as $nilai) {
            if (BigDecimal::of((string) $nilai)->strippedOfTrailingZeros()->getScale() > $desimal) {
                return ['nilai' => sprintf(
                    'Nilai aset %s atau nilai penjualannya lebih halus dari presisi %s (%d desimal), jadi jurnal pelepasannya tidak akan sama persis dengan register.',
                    $aset->kode,
                    $aset->currency_code,
                    $desimal,
                )];
            }
        }

        return [];
    }

    /**
     * Pratinjau jurnal pelepasan (K-22) tanpa menyimpan apa pun.
     *
     * @return array{note: ?string, amounts: Rincian|null, posting: HasilTerbit|null}
     *
     * @throws DisposalPostingFailed
     */
    public function preview(Aset $aset, string $type, string $tanggal, ?string $proceeds, ?string $keterangan): array
    {
        $susunan = $this->susun($aset, $type, $tanggal, $proceeds, ['id' => null, 'kode' => null, 'keterangan' => $keterangan]);

        return [
            'note' => $susunan['note'],
            'amounts' => $susunan['amounts'],
            'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->preview($susunan['input'])),
        ];
    }

    /**
     * Menerbitkan jurnal pelepasan **di dalam transaksi posting dokumen pelepasannya**, sebelum buku asetnya
     * ditutup.
     *
     * @param  array{id: string, kode: string, keterangan: ?string}  $dokumen
     * @return array{note: ?string, posting: HasilTerbit|null}
     *
     * @throws DisposalPostingFailed
     */
    public function publish(Aset $aset, string $type, string $tanggal, ?string $proceeds, array $dokumen): array
    {
        $susunan = $this->susun($aset, $type, $tanggal, $proceeds, $dokumen);

        return [
            'note' => $susunan['note'],
            'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->publish($susunan['input'])),
        ];
    }

    /**
     * @param  array{id: ?string, kode: ?string, keterangan: ?string}  $dokumen
     * @return array{note: ?string, amounts: Rincian|null, input: array<string, mixed>|null}
     */
    private function susun(Aset $aset, string $type, string $tanggal, ?string $proceeds, array $dokumen): array
    {
        if (! in_array($type, [self::SALE, self::SCRAP], true)) {
            throw new InvalidArgumentException(sprintf('Dokumen "%s" bukan pelepasan aset.', $type));
        }
        $book = $this->postedBook($aset);
        if ($book === null) {
            return ['note' => self::TANPA_BUKU, 'amounts' => null, 'input' => null];
        }

        $tenant = (string) $aset->tenant_id;
        $mataUang = (string) $aset->currency_code;
        $desimal = $this->desimal($tenant, $mataUang);
        $skala = static fn (BigDecimal $nilai): string => (string) $nilai->toScale($desimal);

        $perolehan = BigDecimal::of((string) $book->acquisition_value);
        $akumulasi = BigDecimal::of((string) $book->accumulated_depreciation);
        $turun = BigDecimal::of((string) $book->write_down_amount);
        $naik = BigDecimal::of((string) $book->appreciation_amount);
        // Dihitung dari keempat saldonya, bukan dibaca dari `net_book_value`, supaya jurnalnya seimbang
        // dengan sendirinya.
        $nilaiBuku = $perolehan->minus($akumulasi)->minus($turun)->plus($naik);
        $hasil = $type === self::SALE ? BigDecimal::of($proceeds ?? '0') : BigDecimal::zero();
        $labaRugi = $hasil->minus($nilaiBuku);
        $amounts = [
            'book' => (string) $book->book_code,
            'acquisition_value' => $skala($perolehan),
            'accumulated_depreciation' => $skala($akumulasi),
            'write_down_amount' => $skala($turun),
            'appreciation_amount' => $skala($naik),
            'net_book_value' => $skala($nilaiBuku),
            'proceeds' => $skala($hasil),
            'gain_loss' => $skala($labaRugi),
        ];

        $groupId = (string) $aset->group_aset_id;
        $grup = GroupAset::withTrashed()->where('id', $groupId)->toBase()->first(['id', 'kode', 'nama']);
        $kodeGroup = (string) ($grup->kode ?? $groupId);
        $namaGroup = (string) ($grup->nama ?? $groupId);
        $kode = (string) $aset->kode;
        $neraca = (string) ($aset->financial_dimension_org_unit_id ?? $aset->responsible_org_unit_id);
        $pengguna = $this->usageUnit($aset, $tanggal);
        $jual = $type === self::SALE;

        $rencana = [
            ['accumulated_depreciation_account_id', $akumulasi, true, 'Akumulasi penyusutan aset dilepas', $pengguna],
            ['write_down_account_id', $turun, true, 'Akumulasi penurunan nilai aset dilepas', $neraca],
            ['acquisition_account_id', $perolehan, false, 'Harga perolehan aset dilepas', $neraca],
            ['appreciation_account_id', $naik, false, 'Kenaikan nilai aset dilepas', $neraca],
            ['disposal_proceeds_account_id', $hasil, true, 'Hasil penjualan aset', $pengguna],
            $labaRugi->isPositive()
                ? ['disposal_gain_account_id', $labaRugi, false, 'Laba pelepasan aset', $pengguna]
                : ['disposal_loss_account_id', $labaRugi->negated(), true, 'Rugi pelepasan aset', $pengguna],
        ];
        $baris = [];
        foreach ($rencana as [$kolom, $nilai, $debit, $keterangan, $unit]) {
            if ($nilai->isZero()) {
                continue;
            }
            $baris[] = $this->accounts->line(
                $groupId,
                $kodeGroup,
                $kolom,
                $debit ? $skala($nilai) : '0',
                $debit ? '0' : $skala($nilai),
                $keterangan.' · '.$kode.' · '.$namaGroup,
                $unit,
                $tanggal,
            );
        }
        if (count($baris) < 2) {
            // Aset tanpa nilai sama sekali: tidak ada yang perlu dikeluarkan dari buku besar.
            return ['note' => null, 'amounts' => $amounts, 'input' => null];
        }

        $keteranganDokumen = trim((string) ($dokumen['keterangan'] ?? ''));

        return ['note' => null, 'amounts' => $amounts, 'input' => [
            'tenant_id' => $tenant,
            'posting_id' => self::postingId((string) $aset->id),
            'posting_type' => $jual ? self::SALE_TYPE : self::SCRAP_TYPE,
            'legal_entity_id' => (string) $aset->legal_entity_id,
            'currency_code' => $mataUang,
            'posting_date' => $tanggal,
            'document_date' => $tanggal,
            'occurred_at' => now()->toIso8601String(),
            'source_document' => [
                'module' => AcquisitionPosting::MODULE,
                'type' => $type,
                'number' => $dokumen['kode'],
                'description' => mb_substr(($jual ? 'Penjualan aset ' : 'Pemusnahan aset ').$kode.($keteranganDokumen !== '' ? ': '.$keteranganDokumen : ''), 0, 255),
                'id' => $dokumen['id'],
                'url' => '/management-aset/'.$type,
            ],
            'lines' => $baris,
            'details' => ['assets' => [[
                'asset_code' => $kode,
                'asset_group' => $grup->kode ?? null,
                ...$amounts,
            ]]],
        ]];
    }

    /**
     * Buku aset yang di-post ke finance untuk aset ini, atau `null` bila group-nya tidak punya, atau aset
     * ini lahir sebelum buku itu ada di matriks.
     */
    private function postedBook(Aset $aset): ?stdClass
    {
        $bukuId = $this->assets->bukuDiPostId((string) $aset->group_aset_id);
        if ($bukuId === null) {
            return null;
        }

        return BukuAset::query()
            ->where(['aset_id' => $aset->id, 'buku_id' => $bukuId])
            ->toBase()
            ->first(['id', 'book_code', 'acquisition_value', 'accumulated_depreciation', 'write_down_amount', 'appreciation_amount', 'net_book_value']);
    }

    /** @return list<stdClass> */
    private function activeBooks(string $asetId): array
    {
        return array_values(BukuAset::query()->where(['aset_id' => $asetId, 'status' => 'active'])->toBase()->get(['id'])->all());
    }

    /**
     * Unit pengguna aset pada tanggal pelepasan, dari penempatan terakhir — unit yang menanggung beban
     * penyusutannya, sehingga laba/rugi pelepasannya jatuh ke department yang sama.
     */
    private function usageUnit(Aset $aset, string $tanggal): string
    {
        $unit = PenempatanAset::query()
            ->where('aset_id', $aset->id)
            ->whereDate('effective_on', '<=', $tanggal)
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->value('usage_org_unit_id');

        return (string) ($unit ?? $aset->responsible_org_unit_id);
    }

    /** @return int<0, max> */
    private function desimal(string $tenant, string $mataUang): int
    {
        $desimal = $this->precision->amountDecimals($tenant, $mataUang);
        if ($desimal < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }

        return $desimal;
    }

    /**
     * `InvalidPosting` adalah bug penerbit (K-22): dilaporkan, lalu menjadi kegagalan dokumen yang dapat
     * dibaca orang; pemanggilnya membatalkan transaksinya.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     *
     * @throws DisposalPostingFailed
     */
    private function atauGagal(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (InvalidPosting $kegagalan) {
            report($kegagalan);

            throw new DisposalPostingFailed($kegagalan);
        }
    }
}
