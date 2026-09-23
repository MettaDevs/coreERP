<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\AssetPlacement;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Laporan riwayat mutasi aset: satu baris per penempatan aset, memuat tanggal,
 * nomor bukti, lokasi asal dan tujuan, penanggung jawab, serta kondisi aset.
 */
final class AssetMutationReport implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-mutasi-aset';
    }

    public function name(): string
    {
        return 'Laporan mutasi aset';
    }

    public function description(): string
    {
        return 'Riwayat perpindahan lokasi dan serah terima penanggung jawab aset untuk keperluan audit dan monitoring.';
    }

    public function permission(): string
    {
        return 'management-aset.mutasi-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout(
                'standar',
                'Laporan mutasi aset standar (Excel)',
                'Daftar riwayat mutasi aset lengkap dengan lokasi asal, lokasi tujuan, dan penanggung jawab.',
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
            'jumlah_mutasi' => 'Jumlah mutasi',
            'dicetak_pada' => 'Tanggal cetak',
        ];

        $rows = [
            'baris.nomor' => 'No',
            'baris.tanggal_mutasi' => 'Tanggal mutasi',
            'baris.nomor_bukti' => 'No bukti mutasi',
            'baris.kode_aset' => 'Kode aset',
            'baris.nama_aset' => 'Nama aset',
            'baris.spesifikasi' => 'Spesifikasi',
            'baris.lokasi_asal' => 'Lokasi asal',
            'baris.lokasi_tujuan' => 'Lokasi tujuan',
            'baris.penanggung_jawab' => 'Penanggung jawab',
            'baris.pic_penerima' => 'PIC penerima',
            'baris.kondisi_aset' => 'Kondisi aset',
            'baris.keterangan' => 'Keterangan',
        ];

        return [
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // Tabel utama tidak diberi alias agar scope tenant berjalan semestinya.
        $query = AssetPlacement::query()
            ->join('aset_tr_penerimaan_aset as aset', function ($join): void {
                $join->on('aset.id', '=', 'aset_tr_penempatan_aset.asset_id')
                    ->on('aset.tenant_id', '=', 'aset_tr_penempatan_aset.tenant_id');
            })
            ->leftJoin('aset_m_lokasi_aset as lokasi_tujuan', function ($join): void {
                $join->on('lokasi_tujuan.id', '=', 'aset_tr_penempatan_aset.asset_location_id')
                    ->on('lokasi_tujuan.tenant_id', '=', 'aset_tr_penempatan_aset.tenant_id');
            })
            ->leftJoin('aset_m_kondisi_aset as kondisi', function ($join): void {
                $join->on('kondisi.id', '=', 'aset.kondisi_aset_id')
                    ->on('kondisi.tenant_id', '=', 'aset.tenant_id');
            });

        app(OrganizationScope::class)->assetQuery($query, $context->request(), 'aset');

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
            $query->where('aset_tr_penempatan_aset.asset_id', $parameters['asset_id']);
        }
        if (! empty($parameters['dari'])) {
            $query->where('aset_tr_penempatan_aset.effective_on', '>=', $parameters['dari']);
        }
        if (! empty($parameters['sampai'])) {
            $query->where('aset_tr_penempatan_aset.effective_on', '<=', $parameters['sampai']);
        }

        // Subquery untuk lokasi asal dari penempatan sebelumnya untuk aset yang sama
        $lokasiAsalSub = AssetPlacement::from('aset_tr_penempatan_aset as p_prev')
            ->leftJoin('aset_m_lokasi_aset as loc_prev', function ($join): void {
                $join->on('loc_prev.id', '=', 'p_prev.asset_location_id')
                    ->on('loc_prev.tenant_id', '=', 'p_prev.tenant_id');
            })
            ->select('loc_prev.nama')
            ->whereColumn('p_prev.asset_id', 'aset_tr_penempatan_aset.asset_id')
            ->whereColumn('p_prev.tenant_id', 'aset_tr_penempatan_aset.tenant_id')
            ->where(function ($q): void {
                $q->whereColumn('p_prev.effective_on', '<', 'aset_tr_penempatan_aset.effective_on')
                    ->orWhere(function ($q2): void {
                        $q2->whereColumn('p_prev.effective_on', '=', 'aset_tr_penempatan_aset.effective_on')
                            ->whereColumn('p_prev.created_at', '<', 'aset_tr_penempatan_aset.created_at');
                    })
                    ->orWhere(function ($q3): void {
                        $q3->whereColumn('p_prev.effective_on', '=', 'aset_tr_penempatan_aset.effective_on')
                            ->whereColumn('p_prev.created_at', '=', 'aset_tr_penempatan_aset.created_at')
                            ->whereColumn('p_prev.id', '<', 'aset_tr_penempatan_aset.id');
                    });
            })
            ->orderByDesc('p_prev.effective_on')
            ->orderByDesc('p_prev.created_at')
            ->orderByDesc('p_prev.id')
            ->limit(1);

        // Subquery untuk penanggung jawab asal (custodian penempatan sebelumnya)
        $custodianAsalSub = AssetPlacement::from('aset_tr_penempatan_aset as p_prev')
            ->select('p_prev.custodian_user_id')
            ->whereColumn('p_prev.asset_id', 'aset_tr_penempatan_aset.asset_id')
            ->whereColumn('p_prev.tenant_id', 'aset_tr_penempatan_aset.tenant_id')
            ->where(function ($q): void {
                $q->whereColumn('p_prev.effective_on', '<', 'aset_tr_penempatan_aset.effective_on')
                    ->orWhere(function ($q2): void {
                        $q2->whereColumn('p_prev.effective_on', '=', 'aset_tr_penempatan_aset.effective_on')
                            ->whereColumn('p_prev.created_at', '<', 'aset_tr_penempatan_aset.created_at');
                    })
                    ->orWhere(function ($q3): void {
                        $q3->whereColumn('p_prev.effective_on', '=', 'aset_tr_penempatan_aset.effective_on')
                            ->whereColumn('p_prev.created_at', '=', 'aset_tr_penempatan_aset.created_at')
                            ->whereColumn('p_prev.id', '<', 'aset_tr_penempatan_aset.id');
                    });
            })
            ->orderByDesc('p_prev.effective_on')
            ->orderByDesc('p_prev.created_at')
            ->orderByDesc('p_prev.id')
            ->limit(1);

        $rows = $query
            ->selectSub($lokasiAsalSub, 'lokasi_asal')
            ->selectSub($custodianAsalSub, 'custodian_asal')
            ->addSelect([
                'aset_tr_penempatan_aset.id',
                'aset_tr_penempatan_aset.effective_on',
                'aset_tr_penempatan_aset.custodian_user_id',
                'aset_tr_penempatan_aset.reason',
                'aset.kode as asset_kode',
                'aset.nama as asset_nama',
                'aset.model_number as asset_model_number',
                'aset.serial_number as asset_serial_number',
                'lokasi_tujuan.nama as lokasi_tujuan_nama',
                'kondisi.nama as kondisi_nama',
            ])
            ->orderByDesc('aset_tr_penempatan_aset.effective_on')
            ->orderByDesc('aset_tr_penempatan_aset.id')
            ->toBase()
            ->get();

        $groupLabel = ! empty($parameters['group_aset_id'])
            ? (GroupAset::where('id', $parameters['group_aset_id'])->value('nama') ?? 'Semua group aset')
            : 'Semua group aset';

        $golonganLabel = ! empty($parameters['kelompok_harta_fiskal_id'])
            ? (KelompokHartaFiskal::where('id', $parameters['kelompok_harta_fiskal_id'])->value('nama') ?? 'Semua golongan')
            : 'Semua golongan';

        $jenisLabel = ! empty($parameters['jenis_aset_id'])
            ? (JenisAset::where('id', $parameters['jenis_aset_id'])->value('nama') ?? 'Semua jenis')
            : 'Semua jenis';

        $asetLabel = ! empty($parameters['asset_id'])
            ? (Asset::where('id', $parameters['asset_id'])->value('nama') ?? 'Semua aset')
            : 'Semua aset';

        $nomor = 1;
        $tableRows = $rows->map(function (object $row) use (&$nomor): array {
            $spesifikasi = trim(($row->asset_model_number ?? '').' '.($row->asset_serial_number ?? ''));

            return [
                'nomor' => (string) ($nomor++),
                'tanggal_mutasi' => $this->formatDate($row->effective_on),
                'nomor_bukti' => '—',
                'no_bukti' => '—',
                'kode_aset' => (string) ($row->asset_kode ?? '—'),
                'asset_kode' => (string) ($row->asset_kode ?? '—'),
                'nama_aset' => (string) ($row->asset_nama ?? '—'),
                'asset_nama' => (string) ($row->asset_nama ?? '—'),
                'spesifikasi' => $spesifikasi !== '' ? $spesifikasi : '—',
                'lokasi_asal' => (string) ($row->lokasi_asal ?? '—'),
                'lokasi_tujuan' => (string) ($row->lokasi_tujuan_nama ?? '—'),
                'penanggung_jawab' => (string) ($row->custodian_asal ?? '—'),
                'pic_penerima' => (string) ($row->custodian_user_id ?? '—'),
                'kondisi_aset' => (string) ($row->kondisi_nama ?? '—'),
                'keterangan' => (string) ($row->reason ?? '—'),
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
                'jumlah_mutasi' => count($tableRows),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => array_values($tableRows),
            ],
            fileName: 'laporan-mutasi-aset-'.now()->format('Ymd-Hi'),
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
}
