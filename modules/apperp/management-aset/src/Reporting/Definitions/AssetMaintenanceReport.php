<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KelompokHartaFiskal;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Asset;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetChecklist;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Laporan pemeliharaan aset: satu baris per baris pekerjaan (aset yang dirawat/diperbaiki),
 * memuat no bukti work order, tanggal, identitas aset, checklist, analisa perbaikan, dan PIC.
 */
final class AssetMaintenanceReport implements ReportDefinition
{
    public function code(): string
    {
        return 'laporan-pemeliharaan-aset';
    }

    public function name(): string
    {
        return 'Laporan pemeliharaan aset';
    }

    public function description(): string
    {
        return 'Daftar pelaksanaan perawatan berkala dan perbaikan aset beserta riwayat checklist dan teknisi.';
    }

    public function permission(): string
    {
        return 'management-aset.pemeliharaan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout(
                'standar',
                'Laporan pemeliharaan aset standar (Excel)',
                'Daftar rincian pemeliharaan aset per baris pekerjaan lengkap dengan checklist, analisa perbaikan, dan teknisi.',
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
            'jumlah_pekerjaan' => 'Jumlah pekerjaan',
            'dicetak_pada' => 'Tanggal cetak',
        ];

        $rows = [
            'baris.nomor' => 'No',
            'baris.no_bukti' => 'No bukti work order',
            'baris.tanggal' => 'Tanggal work order',
            'baris.asset_kode' => 'Kode aset',
            'baris.asset_nama' => 'Item aset',
            'baris.spesifikasi' => 'Spesifikasi',
            'baris.satuan' => 'Satuan',
            'baris.jumlah' => 'Jumlah',
            'baris.checklist' => 'Item checklist',
            'baris.analisa_perbaikan' => 'Analisa perbaikan',
            'baris.jenis_pemeliharaan' => 'Jenis pemeliharaan',
            'baris.unit_organisasi' => 'Unit organisasi',
            'baris.pic' => 'PIC',
            'baris.status' => 'Status',
        ];

        return [
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        // Tabel utama tidak beralias agar tenant scope terjaga
        $query = PemeliharaanAsetDetail::query()
            ->join('aset_tr_pemeliharaan_aset as wo', function ($join): void {
                $join->on('wo.id', '=', 'aset_tr_pemeliharaan_aset_details.pemeliharaan_aset_id')
                    ->on('wo.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            })
            ->join('aset_tr_penerimaan_aset as aset', function ($join): void {
                $join->on('aset.id', '=', 'aset_tr_pemeliharaan_aset_details.asset_id')
                    ->on('aset.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            })
            ->leftJoin('aset_m_maintenance_job_type as job_type', function ($join): void {
                $join->on('job_type.id', '=', 'aset_tr_pemeliharaan_aset_details.maintenance_job_type_id')
                    ->on('job_type.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            })
            ->leftJoin('aset_m_tipe_work_order as tipe_wo', function ($join): void {
                $join->on('tipe_wo.id', '=', 'wo.tipe_work_order_id')
                    ->on('tipe_wo.tenant_id', '=', 'wo.tenant_id');
            })
            ->leftJoin('aset_m_sebab_kerusakan as sebab', function ($join): void {
                $join->on('sebab.id', '=', 'aset_tr_pemeliharaan_aset_details.sebab_kerusakan_id')
                    ->on('sebab.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            })
            ->leftJoin('aset_m_tindakan_perbaikan as tindakan', function ($join): void {
                $join->on('tindakan.id', '=', 'aset_tr_pemeliharaan_aset_details.tindakan_perbaikan_id')
                    ->on('tindakan.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            })
            ->leftJoin('organizations as org', function ($join): void {
                $join->on('org.id', '=', 'wo.responsible_org_unit_id')
                    ->on('org.tenant_id', '=', 'aset_tr_pemeliharaan_aset_details.tenant_id');
            });

        app(OrganizationScope::class)->query(
            $query,
            $context->request(),
            'wo.legal_entity_id',
            'wo.responsible_org_unit_id'
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
            $query->where('aset_tr_pemeliharaan_aset_details.asset_id', $parameters['asset_id']);
        }
        if (! empty($parameters['dari'])) {
            $query->where('wo.created_at', '>=', $parameters['dari'].' 00:00:00');
        }
        if (! empty($parameters['sampai'])) {
            $query->where('wo.created_at', '<=', $parameters['sampai'].' 23:59:59');
        }

        $rows = $query
            ->select([
                'aset_tr_pemeliharaan_aset_details.id as detail_id',
                'aset_tr_pemeliharaan_aset_details.line_number',
                'aset_tr_pemeliharaan_aset_details.ditugaskan_ke_user_id',
                'aset_tr_pemeliharaan_aset_details.catatan',
                'wo.kode as wo_kode',
                'wo.status as wo_status',
                'wo.created_at as wo_created_at',
                'wo.dijadwalkan_mulai as wo_dijadwalkan_mulai',
                'wo.responsible_org_unit_id as wo_org_unit_id',
                'org.name as org_unit_nama',
                'aset.kode as asset_kode',
                'aset.nama as asset_nama',
                'aset.model_number as asset_model_number',
                'aset.serial_number as asset_serial_number',
                'job_type.nama as job_type_nama',
                'tipe_wo.nama as tipe_wo_nama',
                'sebab.nama as sebab_nama',
                'tindakan.nama as tindakan_nama',
            ])
            ->orderBy('wo.kode')
            ->orderBy('aset_tr_pemeliharaan_aset_details.line_number')
            ->toBase()
            ->get();

        // Kumpulkan detail IDs untuk query checklist
        $detailIds = $rows->pluck('detail_id')->all();
        $checklistMap = [];
        if (! empty($detailIds)) {
            $checklists = PemeliharaanAsetChecklist::whereIn('pemeliharaan_aset_detail_id', $detailIds)
                ->select(['pemeliharaan_aset_detail_id', 'nama', 'result_code', 'nilai'])
                ->toBase()
                ->get();

            foreach ($checklists as $cl) {
                $statusText = $cl->result_code ?? $cl->nilai ?? '';
                $itemText = $statusText !== '' ? "{$cl->nama} ({$statusText})" : $cl->nama;
                $checklistMap[$cl->pemeliharaan_aset_detail_id][] = $itemText;
            }
        }

        // Resolusi label filter
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
        $tableRows = $rows->map(function (object $row) use (&$nomor, $checklistMap): array {
            $spesifikasi = trim(($row->asset_model_number ?? '').' '.($row->asset_serial_number ?? ''));

            $analisaParts = array_filter([
                $row->sebab_nama ? 'Sebab: '.$row->sebab_nama : null,
                $row->tindakan_nama ? 'Tindakan: '.$row->tindakan_nama : null,
                $row->catatan ? 'Catatan: '.$row->catatan : null,
            ]);
            $analisaPerbaikan = ! empty($analisaParts) ? implode('; ', $analisaParts) : '—';

            $checklistItems = $checklistMap[$row->detail_id] ?? [];
            $checklistSummary = ! empty($checklistItems) ? implode(', ', $checklistItems) : '—';

            $tanggalWo = $row->wo_dijadwalkan_mulai ?? $row->wo_created_at;

            $statusLabel = match ($row->wo_status) {
                'draft' => 'Draf',
                'scheduled' => 'Dijadwalkan',
                'in_progress' => 'Sedang Dikerjakan',
                'completed' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                default => (string) ($row->wo_status ?? '—'),
            };

            return [
                'nomor' => (string) ($nomor++),
                'no_bukti' => (string) ($row->wo_kode ?? '—'),
                'tanggal' => $this->formatDate($tanggalWo),
                'asset_kode' => (string) ($row->asset_kode ?? '—'),
                'asset_nama' => (string) ($row->asset_nama ?? '—'),
                'spesifikasi' => $spesifikasi !== '' ? $spesifikasi : '—',
                'satuan' => 'Unit',
                'jumlah' => 1,
                'checklist' => $checklistSummary,
                'analisa_perbaikan' => $analisaPerbaikan,
                'jenis_pemeliharaan' => (string) ($row->job_type_nama ?? $row->tipe_wo_nama ?? '—'),
                'unit_organisasi' => (string) ($row->org_unit_nama ?? $row->wo_org_unit_id ?? '—'),
                'pic' => (string) ($row->ditugaskan_ke_user_id ?? '—'),
                'status' => $statusLabel,
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
                'jumlah_pekerjaan' => count($tableRows),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => array_values($tableRows),
            ],
            fileName: 'laporan-pemeliharaan-aset-'.now()->format('Ymd-Hi'),
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
