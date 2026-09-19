<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Laporan penjualan aset: satu baris per dokumen siklus penjualan aset,
 * memuat tanggal penjualan, kode & nama aset, nilai penjualan, nilai buku, dan laba/rugi.
 */
final class AssetDisposalSaleReport implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-penjualan-aset';
    }

    public function name(): string
    {
        return 'Laporan penjualan aset';
    }

    public function description(): string
    {
        return 'Daftar transaksi penjualan aset lengkap dengan nomor bukti, tanggal penjualan, keterangan, dan nilai penjualan.';
    }

    public function permission(): string
    {
        return 'management-aset.penjualan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout(
                'standar',
                'Laporan penjualan aset standar (Excel)',
                'Daftar transaksi penjualan aset lengkap dengan nomor bukti, tanggal penjualan, keterangan, dan nilai penjualan.',
                'xlsx'
            ),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'group_aset_id' => ['nullable', 'string'],
            'kelompok_harta_fiskal_id' => ['nullable', 'string'],
            'jenis_aset_id' => ['nullable', 'string'],
            'asset_id' => ['nullable', 'string'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_group_aset' => 'Filter group aset',
            'filter_golongan_aset' => 'Filter golongan aset',
            'filter_jenis_aset' => 'Filter jenis aset',
            'filter_nama_aset' => 'Filter nama aset',
            'filter_dari' => 'Filter tanggal mulai',
            'filter_sampai' => 'Filter tanggal akhir',
            'jumlah_penjualan' => 'Jumlah penjualan',
            'total_nilai_penjualan' => 'Total nilai penjualan',
            'dicetak_pada' => 'Tanggal cetak',
        ];

        $rows = [
            'baris.nomor' => 'No',
            'baris.no_bukti' => 'No bukti penjualan',
            'baris.tanggal_penjualan' => 'Tanggal penjualan',
            'baris.kode_aset' => 'Kode aset',
            'baris.nama_aset' => 'Item aset',
            'baris.spesifikasi' => 'Spesifikasi',
            'baris.nilai_penjualan' => 'Nilai penjualan',
            'baris.nilai_buku' => 'Nilai buku',
            'baris.laba_rugi' => 'Laba / rugi',
            'baris.keterangan' => 'Keterangan',
            'baris.status_dokumen' => 'Status dokumen',
        ];

        return [
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // Tabel utama tidak beralias
        $query = DokumenSiklusAset::query()
            ->where('aset_tr_dokumen_siklus_aset.jenis_dokumen', 'penjualan-aset')
            ->leftJoin('aset_tr_penerimaan_aset as aset', function ($join): void {
                $join->on('aset.id', '=', 'aset_tr_dokumen_siklus_aset.asset_id')
                    ->on('aset.tenant_id', '=', 'aset_tr_dokumen_siklus_aset.tenant_id');
            })
            ->leftJoin('aset_tr_buku_aset as buku', function ($join): void {
                $join->on('buku.asset_id', '=', 'aset.id')
                    ->on('buku.tenant_id', '=', 'aset.tenant_id');
            });

        app(OrganizationScope::class)->query(
            $query,
            $context->request(),
            'aset_tr_dokumen_siklus_aset.legal_entity_id',
            'aset_tr_dokumen_siklus_aset.responsible_org_unit_id'
        );

        if (! empty($parameters['group_aset_id'])) {
            $query->where('aset.group_aset_id', $parameters['group_aset_id']);
        }
        if (! empty($parameters['kelompok_harta_fiskal_id'])) {
            $query->where('aset.kelompok_harta_fiskal_id', $parameters['kelompok_harta_fiskal_id']);
        }
        if (! empty($parameters['jenis_aset_id'])) {
            $query->where('aset.jenis_aset_id', $parameters['jenis_aset_id']);
        }
        if (! empty($parameters['asset_id'])) {
            $query->where('aset_tr_dokumen_siklus_aset.asset_id', $parameters['asset_id']);
        }
        if (! empty($parameters['dari'])) {
            $query->where('aset_tr_dokumen_siklus_aset.tanggal', '>=', $parameters['dari']);
        }
        if (! empty($parameters['sampai'])) {
            $query->where('aset_tr_dokumen_siklus_aset.tanggal', '<=', $parameters['sampai']);
        }

        $rows = $query
            ->select([
                'aset_tr_dokumen_siklus_aset.id',
                'aset_tr_dokumen_siklus_aset.kode as dokumen_kode',
                'aset_tr_dokumen_siklus_aset.tanggal as dokumen_tanggal',
                'aset_tr_dokumen_siklus_aset.status as dokumen_status',
                'aset_tr_dokumen_siklus_aset.nilai as dokumen_nilai',
                'aset_tr_dokumen_siklus_aset.keterangan as dokumen_keterangan',
                'aset.kode as asset_kode',
                'aset.nama as asset_nama',
                'aset.model_number as asset_model_number',
                'aset.serial_number as asset_serial_number',
                'buku.net_book_value as buku_net_book_value',
            ])
            ->orderBy('aset_tr_dokumen_siklus_aset.tanggal', 'desc')
            ->orderBy('aset_tr_dokumen_siklus_aset.kode', 'desc')
            ->toBase()
            ->get();

        // Resolusi filter label
        $groupLabel = ! empty($parameters['group_aset_id'])
            ? (GroupAset::where('id', $parameters['group_aset_id'])->value('nama') ?? 'Semua group')
            : 'Semua group';

        $golonganLabel = ! empty($parameters['kelompok_harta_fiskal_id'])
            ? (KelompokHartaFiskal::where('id', $parameters['kelompok_harta_fiskal_id'])->value('label') ?? 'Semua golongan')
            : 'Semua golongan';

        $jenisLabel = ! empty($parameters['jenis_aset_id'])
            ? (JenisAset::where('id', $parameters['jenis_aset_id'])->value('nama') ?? 'Semua jenis')
            : 'Semua jenis';

        $asetLabel = ! empty($parameters['asset_id'])
            ? (Asset::where('id', $parameters['asset_id'])->value('nama') ?? 'Semua aset')
            : 'Semua aset';

        $nomor = 1;
        $totalNilaiPenjualan = 0.0;

        $tableRows = $rows->map(function (object $row) use (&$nomor, &$totalNilaiPenjualan): array {
            $spesifikasi = trim(($row->asset_model_number ?? '').' '.($row->asset_serial_number ?? ''));
            $nilaiPenjualan = (float) ($row->dokumen_nilai ?? 0);
            $totalNilaiPenjualan += $nilaiPenjualan;

            $nilaiBuku = $row->buku_net_book_value !== null ? (float) $row->buku_net_book_value : null;
            $labaRugi = $nilaiBuku !== null ? ($nilaiPenjualan - $nilaiBuku) : null;

            $statusLabel = match ($row->dokumen_status) {
                'draft' => 'Draf',
                'submitted' => 'Diajukan',
                'approved', 'disetujui' => 'Disetujui',
                'completed' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                default => (string) ($row->dokumen_status ?? '—'),
            };

            return [
                'nomor' => (string) ($nomor++),
                'no_bukti' => (string) ($row->dokumen_kode ?? '—'),
                'tanggal_penjualan' => $this->formatDate($row->dokumen_tanggal),
                'asset_kode' => (string) ($row->asset_kode ?? '—'),
                'asset_nama' => (string) ($row->asset_nama ?? '—'),
                'spesifikasi' => $spesifikasi !== '' ? $spesifikasi : '—',
                'nilai_penjualan' => $this->formatCurrency($nilaiPenjualan),
                'nilai_penjualan_raw' => $nilaiPenjualan,
                'nilai_buku' => $nilaiBuku !== null ? $this->formatCurrency($nilaiBuku) : '—',
                'laba_rugi' => $labaRugi !== null ? $this->formatCurrency($labaRugi) : '—',
                'keterangan' => (string) ($row->dokumen_keterangan ?? '—'),
                'status_dokumen' => $statusLabel,
            ];
        })->all();

        return new ReportData(
            fields: [
                'filter_group_aset' => $groupLabel,
                'filter_golongan_aset' => $golonganLabel,
                'filter_jenis_aset' => $jenisLabel,
                'filter_nama_aset' => $asetLabel,
                'filter_dari' => $parameters['dari'] ?? '',
                'filter_sampai' => $parameters['sampai'] ?? '',
                'jumlah_penjualan' => count($tableRows),
                'total_nilai_penjualan' => $this->formatCurrency($totalNilaiPenjualan),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => array_values($tableRows),
            ],
            fileName: 'laporan-penjualan-aset-'.now()->format('Ymd-Hi'),
        );
    }

    private function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('d/m/Y', $timestamp);
    }

    private function formatCurrency(float|int|string $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}
