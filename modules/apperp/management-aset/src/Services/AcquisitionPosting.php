<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\DaftarVendor;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PostingTidakSah;
use App\Support\Modules\Contracts\PresisiMataUang;
use App\Support\Modules\Contracts\SetelanPostingFinance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;
use RuntimeException;
use stdClass;

/**
 * Jurnal perolehan dokumen penerimaan aset, `asset.acquisition` (feed posting finance, TODO 9.4,
 * 9.6, 9.7).
 *
 * Satu posting per penerimaan, terbit di dalam transaksi yang sama dengan asetnya: penerimaan yang
 * gagal disimpan tidak meninggalkan posting, dan penerimaan yang selesai pasti punya posting.
 * `posting_id`-nya diturunkan dari id penerimaan, jadi percobaan ulang tidak pernah menerbitkan
 * posting kedua.
 *
 * **Bentuk jurnalnya** (K-09, K-10, K-11):
 *
 *     Dr harga perolehan                            per group dan unit dimensi
 *     Dr PPN Masukan                                per group dan unit dimensi, bila ada PPN
 *        Cr lawan hutang | perantara | lawan hibah  per group dan unit dimensi
 *
 * Setiap baris menyebut satu kolom posting group satu group. Itu yang membuat akunnya dapat dibaca
 * ulang ketika posting yang tertahan divalidasi ulang (`mapping.reference`, dijawab
 * `PostingGroupAccountResolver`). Unit yang dikirim adalah unit dimensi aset — dari lokasinya, atau
 * unit penggunanya — dan Core yang menurunkan business unit-nya serta memilih dimensi menurut jenis
 * akun.
 *
 * **Pembulatan di sumber** (K-20). Nilai baris penerimaan = bulat(harga satuan × jumlah) ke presisi
 * nilai mata uang, begitu juga PPN-nya, dan jurnal disusun dari nilai yang sudah bulat. Nilai tiap
 * aset adalah pembagian nilai baris yang sudah bulat itu, sehingga jumlah register aset sama persis
 * dengan jurnalnya: 3 × 333.333,333 menjadi 1.000.000,00 di jurnal dan 333.333,34 + 333.333,33 +
 * 333.333,33 di register.
 */
final class AcquisitionPosting
{
    public const MODULE = 'management-aset';

    public const POSTING_TYPE = 'asset.acquisition';

    public const SOURCE_TYPE = 'penerimaan-aset';

    public const POSTING_GROUP_URL = '/management-aset/fixed-aset-posting-profiles';

    private const DIRECT_PAYABLE = 'direct_payable';

    /** Kolom nilai register aset berpresisi dua desimal; nilai yang lebih halus tidak tercatat sama persis. */
    private const REGISTER_DECIMALS = 2;

    /** @var array<string, ?string> */
    private array $bukuDiPost = [];

    public function __construct(
        private readonly PenerbitPosting $publisher,
        private readonly PresisiMataUang $precision,
        private readonly SetelanPostingFinance $settings,
        private readonly DaftarVendor $vendors,
        private readonly AssetPostingAccounts $accounts,
        private readonly PembuatAset $assets,
    ) {}

    public static function postingId(string $receiptId): string
    {
        return 'AST-ACQ-'.$receiptId;
    }

    /**
     * Nilai dan PPN satu baris penerimaan, dibulatkan sekali, beserta pembagiannya ke tiap aset.
     *
     * @return array{value: string, tax: string, unit_values: list<string>, unit_taxes: list<string>}
     */
    public function lineAmounts(string $tenantId, string $currency, stdClass $line): array
    {
        $jumlah = (int) $line->jumlah;
        $desimal = $this->precision->nilai($tenantId, $currency);
        $nilai = $this->precision->bulatkan($tenantId, (string) BigDecimal::of((string) $line->nilai_per_unit)->multipliedBy($jumlah), $currency);
        $pajak = $this->precision->bulatkan($tenantId, (string) BigDecimal::of((string) ($line->ppn_per_unit ?? '0'))->multipliedBy($jumlah), $currency);

        return [
            'value' => $nilai,
            'tax' => $pajak,
            'unit_values' => self::bagi($nilai, $jumlah, $desimal),
            'unit_taxes' => self::bagi($pajak, $jumlah, $desimal),
        ];
    }

    /**
     * Yang menahan penyelesaian penerimaan, berkunci field, dengan pesan untuk pengguna. Kosong
     * berarti boleh diselesaikan.
     *
     * Dipakai `selesaikan()` sebagai validasi dan pratinjau sebagai peringatan, supaya keduanya tidak
     * pernah menjawab berbeda. Masalah pemetaan akun tidak ada di sini: posting itu tetap terbit
     * sebagai `held` dan penerimaannya tetap selesai (K-18).
     *
     * @param  Collection<int, stdClass>  $lines
     * @return array<string, string>
     */
    public function blockers(stdClass $receipt, Collection $lines): array
    {
        $masalah = [];
        $mataUang = (string) $receipt->currency_code;
        try {
            $desimal = $this->precision->nilai((string) $receipt->tenant_id, $mataUang);
            if ($desimal > self::REGISTER_DECIMALS) {
                $masalah['currency_code'] = sprintf(
                    'Presisi nilai %s (%d desimal) lebih halus dari yang dapat dicatat register aset (%d desimal). Ubah presisinya di Data referensi › Mata uang.',
                    $mataUang,
                    $desimal,
                    self::REGISTER_DECIMALS,
                );
            }
        } catch (RuntimeException $kegagalan) {
            $masalah['currency_code'] = $kegagalan->getMessage();
        }

        $cara = self::cara($receipt);
        if (! in_array($cara, AcquisitionMethod::RECEIPT, true)) {
            $masalah['cara_perolehan'] = 'Saldo awal belum dapat dicatat lewat penerimaan.';
        } elseif ($cara === AcquisitionMethod::PURCHASE && ($receipt->vendor_id ?? null) === null && $this->mode($receipt) === self::DIRECT_PAYABLE) {
            $masalah['vendor_id'] = 'Pilih vendornya. Entitas legal ini mencatat pembelian aset langsung sebagai hutang ke vendor, jadi vendor wajib diisi.';
        }

        $grup = $this->groups($lines);
        foreach ($lines->values() as $indeks => $line) {
            $groupId = (string) $line->group_aset_id;
            if ($this->bukuDiPost($groupId) === null) {
                $masalah['details.'.$indeks.'.group_aset_id'] = sprintf(
                    'Group %s belum punya buku yang di-post ke finance. Tambahkan buku yang lapisan posting-nya bukan "none" di matriks group x buku (Master data › Group aset), lalu selesaikan lagi.',
                    $grup[$groupId]->kode ?? $groupId,
                );
            }
        }

        return $masalah;
    }

    /**
     * Masukan `PenerbitPosting` untuk penerimaan ini, atau `null` bila tidak ada nilai yang dijurnal:
     * seluruh barisnya bernilai nol, dan penerbit posting menolak baris tanpa debit maupun kredit.
     *
     * `$assets` hanya ada saat penerimaan benar-benar diselesaikan. Pratinjau belum punya kode aset,
     * jadi rinciannya dikosongkan; rincian tidak ikut menentukan isi jurnal.
     *
     * @param  Collection<int, stdClass>  $lines
     * @param  list<array{asset_code: string, group_aset_id: string, acquisition_value: string, tax_amount: string}>|null  $assets
     * @return array<string, mixed>|null
     */
    public function input(stdClass $receipt, Collection $lines, ?array $assets = null): ?array
    {
        $tenant = (string) $receipt->tenant_id;
        $mataUang = (string) $receipt->currency_code;
        $tanggal = self::tanggal($receipt->tanggal);
        $cara = self::cara($receipt);
        $mode = $this->mode($receipt);
        $grup = $this->groups($lines);

        // Nilai per pasangan group dan unit dimensi, dalam urutan kemunculan barisnya.
        $kelompok = [];
        foreach ($lines as $line) {
            $groupId = (string) $line->group_aset_id;
            $unit = $this->assets->unitDimensi($groupId, $receipt->lokasi_aset_id ?? null, (string) $receipt->responsible_org_unit_id);
            $nilai = $this->lineAmounts($tenant, $mataUang, $line);
            $kunci = $groupId.'|'.$unit;
            $kelompok[$kunci] ??= ['group' => $groupId, 'unit' => $unit, 'value' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
            $kelompok[$kunci]['value'] = $kelompok[$kunci]['value']->plus($nilai['value']);
            $kelompok[$kunci]['tax'] = $kelompok[$kunci]['tax']->plus($nilai['tax']);
        }

        $kolomDebit = $this->accounts->acquisitionColumn($cara);
        $kolomKredit = $this->accounts->offsetColumn($cara, $mode);
        $vendor = ($receipt->vendor_id ?? null) === null ? null : $this->vendors->satu($tenant, (string) $receipt->vendor_id);
        $pemetaan = [];
        $kode = (string) $receipt->kode;

        $baris = [];
        foreach ($kelompok as $bagian) {
            if (! $bagian['value']->isZero()) {
                $namaGroup = $grup[$bagian['group']]->nama ?? $bagian['group'];
                $baris[] = $this->baris($bagian, $kolomDebit, (string) $bagian['value'], '0', $kode.' · '.$namaGroup, $tanggal, $grup, $pemetaan);
            }
        }
        foreach ($kelompok as $bagian) {
            if (! $bagian['tax']->isZero()) {
                $namaGroup = $grup[$bagian['group']]->nama ?? $bagian['group'];
                $baris[] = $this->baris($bagian, 'input_vat_account_id', (string) $bagian['tax'], '0', 'PPN Masukan · '.$kode.' · '.$namaGroup, $tanggal, $grup, $pemetaan);
            }
        }
        foreach ($kelompok as $bagian) {
            $total = $bagian['value']->plus($bagian['tax']);
            if ($total->isZero()) {
                continue;
            }
            $namaGroup = $grup[$bagian['group']]->nama ?? $bagian['group'];
            $keterangan = match (true) {
                $cara === AcquisitionMethod::GRANT => 'Hibah · '.$kode.' · '.$namaGroup,
                $vendor !== null => $vendor['name'].' · '.$kode,
                default => $kode.' · '.$namaGroup,
            };
            $baris[] = $this->baris($bagian, $kolomKredit, '0', (string) $total, $keterangan, $tanggal, $grup, $pemetaan);
        }

        if ($baris === []) {
            return null;
        }

        return [
            'tenant_id' => $tenant,
            'posting_id' => self::postingId((string) $receipt->id),
            'posting_type' => self::POSTING_TYPE,
            'legal_entity_id' => (string) $receipt->legal_entity_id,
            'currency_code' => $mataUang,
            'posting_date' => $tanggal,
            'document_date' => ($receipt->vendor_invoice_date ?? null) !== null ? self::tanggal($receipt->vendor_invoice_date) : $tanggal,
            'occurred_at' => now()->toIso8601String(),
            'settlement_mode' => $mode,
            'requires_vendor' => $cara === AcquisitionMethod::PURCHASE && $mode === self::DIRECT_PAYABLE,
            'vendor_id' => $receipt->vendor_id ?? null,
            'vendor_invoice_reference' => $receipt->vendor_invoice_reference ?? null,
            'source_document' => [
                'module' => self::MODULE,
                'type' => self::SOURCE_TYPE,
                'number' => $kode,
                'description' => mb_substr(trim((string) ($receipt->keterangan ?? '')) !== '' ? trim((string) $receipt->keterangan) : 'Penerimaan aset '.$kode, 0, 255),
                'id' => (string) $receipt->id,
                'url' => '/management-aset/inventarisasi-aset/penerimaan/'.$receipt->id,
            ],
            'lines' => $baris,
            'details' => $assets === null ? [] : ['assets' => array_map(fn (array $aset): array => [
                'asset_code' => $aset['asset_code'],
                'asset_group' => $grup[$aset['group_aset_id']]->kode ?? null,
                'book' => $this->bukuDiPost($aset['group_aset_id']),
                'acquisition_value' => $aset['acquisition_value'],
                'tax_amount' => $aset['tax_amount'],
            ], $assets)],
        ];
    }

    /**
     * Pratinjau jurnal dan masalahnya sebelum penerimaan diselesaikan (TODO 9.3.2, K-22), dengan
     * pemeriksaan yang sama persis dengan penerbitan. `posting` `null` berarti tidak ada nilai yang
     * akan dijurnal.
     *
     * @param  Collection<int, stdClass>  $lines
     * @return array{blockers: array<string, string>, posting: array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}|null}
     */
    public function preview(stdClass $receipt, Collection $lines): array
    {
        $blockers = $this->blockers($receipt, $lines);
        // Tanpa presisi mata uang, nilai tidak dapat dibulatkan dan jurnalnya tidak dapat disusun.
        if (isset($blockers['currency_code'])) {
            return ['blockers' => $blockers, 'posting' => null];
        }
        $input = $this->input($receipt, $lines);

        return [
            'blockers' => $blockers,
            'posting' => $input === null ? null : $this->atauGagal(fn (): array => $this->publisher->pratinjau($input)),
        ];
    }

    /**
     * Menerbitkan posting perolehan di dalam transaksi penyelesaian penerimaan. `null` bila tidak ada
     * nilai yang dijurnal.
     *
     * @param  Collection<int, stdClass>  $lines
     * @param  list<array{asset_code: string, group_aset_id: string, acquisition_value: string, tax_amount: string}>  $assets
     * @return array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}|null
     *
     * @throws AcquisitionPostingFailed
     */
    public function publish(stdClass $receipt, Collection $lines, array $assets): ?array
    {
        $input = $this->input($receipt, $lines, $assets);

        return $input === null ? null : $this->atauGagal(fn (): array => $this->publisher->terbitkan($input));
    }

    /**
     * `PostingTidakSah` adalah bug penerbit, bukan keadaan yang diserahkan ke pengguna (K-22). Ia
     * tetap dilaporkan ke pemantauan kesalahan, lalu diterjemahkan menjadi kegagalan dokumen yang
     * dapat dibaca orang; pemanggilnya membatalkan transaksi dokumen.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    private function atauGagal(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (PostingTidakSah $kegagalan) {
            report($kegagalan);

            throw new AcquisitionPostingFailed($kegagalan);
        }
    }

    /**
     * @param  array{group: string, unit: string, value: BigDecimal, tax: BigDecimal}  $bagian
     * @param  array<string, stdClass>  $grup
     * @param  array<string, ?AssetPostingGroup>  $pemetaan
     * @return array<string, mixed>
     */
    private function baris(array $bagian, string $kolom, string $debit, string $kredit, string $keterangan, string $tanggal, array $grup, array &$pemetaan): array
    {
        $groupId = $bagian['group'];
        if (! array_key_exists($groupId, $pemetaan)) {
            $pemetaan[$groupId] = $this->accounts->effective($groupId, $tanggal);
        }
        $akun = $pemetaan[$groupId]?->getAttribute($kolom);
        $label = AssetPostingGroup::ACCOUNTS[$kolom];
        // Huruf pertama label dikecilkan kecuali singkatan seperti "PPN Masukan".
        $label = ctype_upper(substr($label, 1, 1)) ? $label : lcfirst($label);

        return [
            'account_id' => is_string($akun) ? $akun : null,
            'debit' => $debit,
            'credit' => $kredit,
            'description' => mb_substr($keterangan, 0, 255),
            'org_unit_id' => $bagian['unit'],
            'mapping' => [
                'label' => sprintf('Group %s · %s', $grup[$groupId]->kode ?? $groupId, $label),
                'fix_url' => self::POSTING_GROUP_URL,
                'reference' => PostingGroupAccountResolver::reference($groupId, $kolom),
            ],
        ];
    }

    private function bukuDiPost(string $groupId): ?string
    {
        if (! array_key_exists($groupId, $this->bukuDiPost)) {
            $this->bukuDiPost[$groupId] = $this->assets->bukuDiPost($groupId);
        }

        return $this->bukuDiPost[$groupId];
    }

    private function mode(stdClass $receipt): string
    {
        return $this->settings->modePenyelesaian((string) $receipt->legal_entity_id, self::tanggal($receipt->tanggal));
    }

    /**
     * Group aset yang disebut baris-baris itu, termasuk yang sudah diarsipkan: dokumen lama tetap
     * menyebut kodenya.
     *
     * @param  Collection<int, stdClass>  $lines
     * @return array<string, stdClass>
     */
    private function groups(Collection $lines): array
    {
        return GroupAset::withTrashed()
            ->whereIn('id', $lines->pluck('group_aset_id')->map(static fn ($id): string => (string) $id)->unique()->values()->all())
            ->toBase()
            ->get(['id', 'kode', 'nama'])
            ->keyBy('id')
            ->all();
    }

    private static function cara(stdClass $receipt): string
    {
        return (string) ($receipt->cara_perolehan ?? AcquisitionMethod::PURCHASE);
    }

    private static function tanggal(mixed $nilai): string
    {
        return substr((string) $nilai, 0, 10);
    }

    /**
     * Membagi nilai yang sudah bulat ke `$n` aset tanpa kehilangan satu satuan pun: setiap aset
     * mendapat bagian yang dibulatkan ke bawah, dan sisa pembulatannya dibagikan satu satuan
     * terkecil ke aset pertama. Jumlah hasilnya selalu sama dengan nilainya.
     *
     * @return list<string>
     */
    private static function bagi(string $nilai, int $n, int $desimal): array
    {
        // Pola yang sama dengan `MoneyPrecision::round()` di Core.
        if ($desimal < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }
        $total = BigDecimal::of($nilai)->toScale($desimal);
        $bagian = $total->dividedBy($n, $desimal, RoundingMode::Down);
        $satuan = BigDecimal::one()->withPointMovedLeft($desimal);
        $sisa = $total->minus($bagian->multipliedBy($n))->dividedBy($satuan, 0)->toInt();

        $hasil = [];
        for ($i = 0; $i < $n; $i++) {
            $hasil[] = (string) ($i < $sisa ? $bagian->plus($satuan) : $bagian);
        }

        return $hasil;
    }
}
