<?php

namespace Modules\Apperp\ManagementAset\Services;

use App\Support\Modules\Contracts\DaftarVendor;
use App\Support\Modules\Contracts\PenerbitPosting;
use App\Support\Modules\Contracts\PostingTidakSah;
use App\Support\Modules\Contracts\PresisiMataUang;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Modules\Apperp\ManagementAset\Models\master\AssetPostingGroup;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAset;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Jurnal koreksi nilai perolehan aset, `asset.acquisition_adjustment` (feed posting finance, TODO 12).
 *
 * Satu posting per koreksi, terbit di dalam transaksi koreksinya dan merujuk jurnal perolehan aset itu
 * (`adjusts_posting_id`): `AST-ACQ-` untuk pembelian dan hibah, `AST-OPB-` untuk saldo awal. Isinya
 * satu pasang baris untuk selisihnya, dengan akun yang sama dengan jurnal asalnya (K-10, K-33):
 *
 *     Dr harga perolehan                     selisih naik
 *        Cr lawan hutang | perantara          pembelian, menurut mode yang tercatat di jurnal asal
 *        Cr lawan hibah                        hibah
 *        Cr penyeimbang saldo awal             saldo awal
 *
 * Selisih turun membalik arahnya. PPN tidak ikut: yang dikoreksi nilai perolehan, bukan pajaknya. Seperti
 * *acquisition adjustment* di F&O, satu posting hanya menyebut satu aset, dan dimensinya dimensi aset itu
 * saat dikoreksi.
 *
 * **Tanggalnya hari koreksi dilakukan** (K-34, K-17): koreksi masuk ke periode yang masih terbuka dan
 * tidak menulis ulang periode jurnal asalnya. Pemanggil yang menentukan tanggal itu menurut jam penggunanya.
 *
 * **Jurnal asal yang dicatat manual membuat koreksinya manual juga** (K-35): tidak ada posting, dan
 * register tetap berubah. Aset tanpa jurnal perolehan — diterima sebelum feed ada, atau dari penerimaan
 * bernilai nol — juga tanpa posting.
 *
 * @phpstan-type HasilTerbit array{posting_id: string, status: string, problems: list<array<string, mixed>>, payload: array<string, mixed>, created: bool}
 */
final class AcquisitionAdjustment
{
    public const POSTING_TYPE = 'asset.acquisition_adjustment';

    public const SOURCE_TYPE = 'koreksi-nilai-aset';

    private const DIRECT_PAYABLE = 'direct_payable';

    private const TANPA_JURNAL = 'Aset ini tidak punya jurnal perolehan di aplikasi finance, jadi koreksi nilainya hanya mengubah register aset.';

    private const ASAL_MANUAL = 'Jurnal perolehan aset ini dicatat manual di aplikasi finance, jadi koreksinya juga dicatat manual di sana. Nilai di register aset tetap berubah.';

    public function __construct(
        private readonly PenerbitPosting $publisher,
        private readonly PresisiMataUang $precision,
        private readonly DaftarVendor $vendors,
        private readonly AssetPostingAccounts $accounts,
        private readonly PembuatAset $assets,
    ) {}

    /** `posting_id` koreksi ke-`$urut` satu aset. */
    public static function postingId(string $asetId, int $urut): string
    {
        return sprintf('AST-ADJ-%s-%d', $asetId, $urut);
    }

    /**
     * Yang menahan koreksi nilai perolehan, berkunci field; kosong berarti boleh. Selisih yang lebih
     * halus dari presisi mata uang tidak dapat dijurnal sama persis dengan register, dan tidak dibulatkan
     * diam-diam (K-18, K-20).
     *
     * @return array<string, string>
     */
    public function blockers(Aset $aset, string $sesudah): array
    {
        $mataUang = (string) $aset->currency_code;
        try {
            $desimal = $this->precision->nilai((string) $aset->tenant_id, $mataUang);
        } catch (RuntimeException $kegagalan) {
            return ['acquisition_value' => $kegagalan->getMessage()];
        }
        $selisih = BigDecimal::of($sesudah)->minus((string) $aset->acquisition_value);
        if ($selisih->strippedOfTrailingZeros()->getScale() > $desimal) {
            return ['acquisition_value' => sprintf(
                'Selisih koreksinya lebih halus dari presisi %s (%d desimal), jadi jurnal koreksinya tidak akan sama persis dengan register. Tulis nilai perolehan dengan paling banyak %d desimal.',
                $mataUang,
                $desimal,
                $desimal,
            )];
        }

        return [];
    }

    /**
     * Pratinjau koreksi (K-22, K-36): selisihnya, catatan bila tidak ada jurnal yang terbit, dan jurnal
     * koreksinya dengan pemeriksaan yang sama persis dengan penerbitan. Tidak menyimpan apa pun.
     *
     * @return array{difference: string, note: ?string, posting: HasilTerbit|null}
     *
     * @throws AcquisitionAdjustmentFailed
     */
    public function preview(Aset $aset, string $sesudah, string $tanggal, string $alasan): array
    {
        $susunan = $this->susun($aset, (string) $aset->acquisition_value, $sesudah, $tanggal, $alasan);

        return [
            'difference' => $susunan['difference'],
            'note' => $susunan['note'],
            'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->pratinjau($susunan['input'])),
        ];
    }

    /**
     * Menerbitkan jurnal koreksi **di dalam transaksi koreksi pemanggil**, yang sudah mengunci baris
     * asetnya: nomor urut koreksi karena itu tidak pernah dipakai dua kali. `$aset` adalah keadaan
     * sebelum dikoreksi.
     *
     * @return array{note: ?string, posting: HasilTerbit|null}
     *
     * @throws AcquisitionAdjustmentFailed
     */
    public function publish(Aset $aset, string $sebelum, string $sesudah, string $tanggal, string $alasan): array
    {
        $susunan = $this->susun($aset, $sebelum, $sesudah, $tanggal, $alasan);

        return [
            'note' => $susunan['note'],
            'posting' => $susunan['input'] === null ? null : $this->atauGagal(fn (): array => $this->publisher->terbitkan($susunan['input'])),
        ];
    }

    /**
     * Masukan `PenerbitPosting` untuk satu koreksi, atau catatan kenapa tidak ada jurnal.
     *
     * @return array{difference: string, note: ?string, input: array<string, mixed>|null}
     *
     * @throws AcquisitionAdjustmentFailed
     */
    private function susun(Aset $aset, string $sebelum, string $sesudah, string $tanggal, string $alasan): array
    {
        $tenant = (string) $aset->tenant_id;
        $mataUang = (string) $aset->currency_code;
        $desimal = $this->desimal($tenant, $mataUang);
        $selisih = BigDecimal::of($sesudah)->minus($sebelum);
        $hasil = ['difference' => (string) $selisih->toScale($desimal), 'note' => null, 'input' => null];
        if ($selisih->isZero()) {
            return $hasil;
        }

        $penerimaan = $aset->penerimaan_aset_id === null ? null : PenerimaanAset::withTrashed()
            ->where('id', $aset->penerimaan_aset_id)
            ->toBase()
            ->first(['id', 'kode', 'cara_perolehan', 'vendor_id', 'vendor_invoice_reference']);
        if ($penerimaan === null) {
            return ['note' => self::TANPA_JURNAL] + $hasil;
        }
        $cara = (string) ($penerimaan->cara_perolehan ?? AcquisitionMethod::PURCHASE);
        $asal = AcquisitionPosting::postingId((string) $penerimaan->id, $cara);
        $keadaan = $this->publisher->status($tenant, $asal);
        if ($keadaan === null) {
            return ['note' => self::TANPA_JURNAL] + $hasil;
        }
        if ($keadaan['status'] === 'manual') {
            return ['note' => self::ASAL_MANUAL] + $hasil;
        }
        $mode = $keadaan['settlement_mode'];
        if ($mode === null && $cara === AcquisitionMethod::PURCHASE) {
            // Tanpa mode asal, akun lawannya tidak dapat dipilih; menebaknya dari setelan hari ini adalah
            // jalan pintas yang dilarang K-10.
            throw $this->gagal(new RuntimeException(sprintf('Jurnal perolehan %s tidak mencatat mode penyelesaiannya.', $asal)));
        }

        $groupId = (string) $aset->group_aset_id;
        $grup = GroupAset::withTrashed()->where('id', $groupId)->toBase()->first(['id', 'kode', 'nama']);
        $unit = (string) ($aset->financial_dimension_org_unit_id ?? $aset->responsible_org_unit_id);
        $nilai = (string) $selisih->abs()->toScale($desimal);
        $naik = $selisih->isPositive();
        $pembelian = $cara === AcquisitionMethod::PURCHASE;
        $vendor = $pembelian && $penerimaan->vendor_id !== null ? $this->vendors->satu($tenant, (string) $penerimaan->vendor_id) : null;
        $kode = (string) $aset->kode;
        $namaGroup = (string) ($grup->nama ?? $groupId);
        $pemetaan = $this->accounts->effective($groupId, $tanggal);

        $kolomLawan = $this->accounts->offsetColumn($cara, (string) $mode);
        $keteranganLawan = match (true) {
            $cara === AcquisitionMethod::GRANT => 'Koreksi hibah · '.$kode.' · '.$namaGroup,
            $cara === AcquisitionMethod::OPENING_BALANCE => 'Koreksi saldo awal · '.$kode.' · '.$namaGroup,
            $vendor !== null => $vendor['name'].' · koreksi '.$kode,
            default => 'Koreksi nilai perolehan · '.$kode.' · '.$namaGroup,
        };

        $input = [
            'tenant_id' => $tenant,
            'posting_id' => self::postingId((string) $aset->id, $this->urutBerikutnya($tenant, (string) $aset->id)),
            'posting_type' => self::POSTING_TYPE,
            'legal_entity_id' => (string) $aset->legal_entity_id,
            'currency_code' => $mataUang,
            'posting_date' => $tanggal,
            'document_date' => $tanggal,
            'occurred_at' => now()->toIso8601String(),
            'settlement_mode' => $mode,
            'requires_vendor' => $pembelian && $mode === self::DIRECT_PAYABLE,
            'vendor_id' => $pembelian ? $penerimaan->vendor_id : null,
            'vendor_invoice_reference' => $pembelian ? $penerimaan->vendor_invoice_reference : null,
            'adjusts_posting_id' => $asal,
            'source_document' => [
                'module' => AcquisitionPosting::MODULE,
                'type' => self::SOURCE_TYPE,
                'number' => $kode,
                'description' => mb_substr('Koreksi nilai perolehan '.$kode.': '.$alasan, 0, 255),
                'id' => (string) $aset->id,
                'url' => '/management-aset/inventarisasi-aset/'.$aset->id,
            ],
            'lines' => [
                $this->baris($pemetaan, $grup, $groupId, $this->accounts->acquisitionColumn($cara), $naik ? $nilai : '0', $naik ? '0' : $nilai, 'Koreksi nilai perolehan · '.$kode.' · '.$namaGroup, $unit),
                $this->baris($pemetaan, $grup, $groupId, $kolomLawan, $naik ? '0' : $nilai, $naik ? $nilai : '0', $keteranganLawan, $unit),
            ],
            'details' => [
                'assets' => [[
                    'asset_code' => $kode,
                    'asset_group' => $grup->kode ?? null,
                    'book' => $this->assets->bukuDiPost($groupId),
                    'acquisition_value_before' => (string) BigDecimal::of($sebelum)->toScale($desimal),
                    'acquisition_value_after' => (string) BigDecimal::of($sesudah)->toScale($desimal),
                    'adjustment_amount' => (string) $selisih->toScale($desimal),
                ]],
                'reason' => $alasan,
            ],
        ];

        return ['input' => $input] + $hasil;
    }

    /**
     * Presisi nilai mata uang, dijamin tidak negatif — pola yang sama dengan `DepreciationPosting`.
     *
     * @return int<0, max>
     */
    private function desimal(string $tenant, string $mataUang): int
    {
        $desimal = $this->precision->nilai($tenant, $mataUang);
        if ($desimal < 0) {
            throw new InvalidArgumentException('Jumlah desimal tidak boleh negatif.');
        }

        return $desimal;
    }

    /**
     * Nomor urut koreksi berikutnya untuk aset ini: satu lebih dari koreksi yang sudah terbit. Pemanggil
     * `publish()` memegang kunci baris aset, jadi dua koreksi serentak tidak pernah mendapat nomor sama.
     */
    private function urutBerikutnya(string $tenant, string $asetId): int
    {
        $urut = 1;
        while ($this->publisher->status($tenant, self::postingId($asetId, $urut)) !== null) {
            $urut++;
        }

        return $urut;
    }

    /** @return array<string, mixed> */
    private function baris(?AssetPostingGroup $pemetaan, ?stdClass $grup, string $groupId, string $kolom, string $debit, string $kredit, string $keterangan, string $unit): array
    {
        $akun = $pemetaan?->getAttribute($kolom);
        $label = AssetPostingGroup::ACCOUNTS[$kolom];
        // Huruf pertama label dikecilkan kecuali singkatan seperti "PPN Masukan".
        $label = ctype_upper(substr($label, 1, 1)) ? $label : lcfirst($label);

        return [
            'account_id' => is_string($akun) ? $akun : null,
            'debit' => $debit,
            'credit' => $kredit,
            'description' => mb_substr($keterangan, 0, 255),
            'org_unit_id' => $unit,
            'mapping' => [
                'label' => sprintf('Group %s · %s', $grup->kode ?? $groupId, $label),
                'fix_url' => AcquisitionPosting::POSTING_GROUP_URL,
                'reference' => PostingGroupAccountResolver::reference($groupId, $kolom),
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     *
     * @throws AcquisitionAdjustmentFailed
     */
    private function atauGagal(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (PostingTidakSah $kegagalan) {
            throw $this->gagal($kegagalan);
        }
    }

    private function gagal(Throwable $kegagalan): AcquisitionAdjustmentFailed
    {
        report($kegagalan);

        return new AcquisitionAdjustmentFailed($kegagalan);
    }
}
