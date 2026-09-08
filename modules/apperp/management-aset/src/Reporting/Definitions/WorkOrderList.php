<?php

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Illuminate\Support\Facades\DB;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

/**
 * Daftar work order untuk dianalisis di Excel: satu baris per work order, dengan filter
 * status dan rentang tanggal. Ini laporan "analitik" dalam pembagian Business Central —
 * layout bawaannya Excel, bukan Word — dan ekspornya dikerjakan worker supaya daftar
 * ribuan baris tidak menahan layar.
 */
final class WorkOrderList implements ReportDefinition
{
    public function code(): string
    {
        return 'daftar-work-order';
    }

    public function name(): string
    {
        return 'Daftar work order';
    }

    public function description(): string
    {
        return 'Seluruh work order dalam jangkauan Anda, satu baris per work order, untuk diolah di Excel.';
    }

    public function permission(): string
    {
        return 'management-aset.pemeliharaan-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [
            new BuiltinLayout('standar', 'Daftar work order standar (Excel)', 'Satu lembar dengan kolom identitas, jadwal, dan ringkasan jam.', 'xlsx'),
        ];
    }

    public function parameterRules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:draft,dijadwalkan,dikerjakan,selesai,ditutup,dibatalkan'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ];
    }

    public function fields(): array
    {
        $header = [
            'filter_status' => 'Filter status',
            'filter_dari' => 'Filter tanggal mulai',
            'filter_sampai' => 'Filter tanggal akhir',
            'jumlah_work_order' => 'Jumlah work order',
            'dicetak_pada' => 'Tanggal cetak',
        ];
        $rows = [
            'baris.kode' => 'Nomor work order',
            'baris.status' => 'Status',
            'baris.tipe_work_order' => 'Tipe work order',
            'baris.tingkat_layanan' => 'Tingkat layanan',
            'baris.keterangan' => 'Keterangan',
            'baris.jumlah_baris' => 'Jumlah baris pekerjaan',
            'baris.estimasi_jam' => 'Total estimasi jam',
            'baris.aktual_jam' => 'Total aktual jam',
            'baris.diharapkan_mulai' => 'Diharapkan mulai',
            'baris.dijadwalkan_mulai' => 'Dijadwalkan mulai',
            'baris.dijadwalkan_selesai' => 'Dijadwalkan selesai',
            'baris.aktual_mulai' => 'Aktual mulai',
            'baris.aktual_selesai' => 'Aktual selesai',
            'baris.dibuat_pada' => 'Dibuat pada',
        ];

        return [
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => null], array_keys($header), $header),
            ...array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'table' => 'baris'], array_keys($rows), $rows),
        ];
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $tenant = $context->tenantId;
        $query = DB::table('aset_tr_pemeliharaan_aset as wo')
            ->leftJoin('aset_m_tipe_work_order as tipe', fn ($join) => $join->on('tipe.id', '=', 'wo.tipe_work_order_id')->on('tipe.tenant_id', '=', 'wo.tenant_id'))
            ->leftJoin('aset_m_tingkat_layanan as layanan', fn ($join) => $join->on('layanan.id', '=', 'wo.tingkat_layanan_id')->on('layanan.tenant_id', '=', 'wo.tenant_id'))
            ->where('wo.tenant_id', $tenant)
            ->whereNull('wo.deleted_at');
        app(OrganizationScope::class)->query($query, $context->request(), 'wo.legal_entity_id', 'wo.responsible_org_unit_id');
        if (! empty($parameters['status'])) {
            $query->where('wo.status', $parameters['status']);
        }
        if (! empty($parameters['dari'])) {
            $query->where('wo.created_at', '>=', $parameters['dari'].' 00:00:00');
        }
        if (! empty($parameters['sampai'])) {
            $query->where('wo.created_at', '<=', $parameters['sampai'].' 23:59:59');
        }

        $rows = $query
            ->selectSub(
                DB::table('aset_tr_pemeliharaan_aset_details as d')->selectRaw('count(*)')
                    ->whereColumn('d.pemeliharaan_aset_id', 'wo.id')->where('d.tenant_id', $tenant),
                'jumlah_baris',
            )
            ->selectSub(
                DB::table('aset_tr_pemeliharaan_aset_details as d')->selectRaw('coalesce(sum(d.estimasi_jam), 0)')
                    ->whereColumn('d.pemeliharaan_aset_id', 'wo.id')->where('d.tenant_id', $tenant),
                'estimasi_jam',
            )
            ->selectSub(
                DB::table('aset_tr_pemeliharaan_aset_details as d')->selectRaw('coalesce(sum(d.aktual_jam), 0)')
                    ->whereColumn('d.pemeliharaan_aset_id', 'wo.id')->where('d.tenant_id', $tenant),
                'aktual_jam',
            )
            ->addSelect(['wo.*', 'tipe.nama as tipe_nama', 'layanan.nama as layanan_nama'])
            ->orderBy('wo.kode')
            ->get();

        return new ReportData(
            fields: [
                'filter_status' => $parameters['status'] ?? 'Semua',
                'filter_dari' => $parameters['dari'] ?? '',
                'filter_sampai' => $parameters['sampai'] ?? '',
                'jumlah_work_order' => $rows->count(),
                'dicetak_pada' => now()->format('d/m/Y H:i'),
            ],
            tables: [
                'baris' => $rows->map(fn (object $wo): array => [
                    'kode' => $wo->kode,
                    'status' => $wo->status,
                    'tipe_work_order' => $wo->tipe_nama,
                    'tingkat_layanan' => $wo->layanan_nama,
                    'keterangan' => $wo->keterangan,
                    'jumlah_baris' => (int) $wo->jumlah_baris,
                    'estimasi_jam' => round((float) $wo->estimasi_jam, 2),
                    'aktual_jam' => round((float) $wo->aktual_jam, 2),
                    'diharapkan_mulai' => $this->dateTime($wo->diharapkan_mulai),
                    'dijadwalkan_mulai' => $this->dateTime($wo->dijadwalkan_mulai),
                    'dijadwalkan_selesai' => $this->dateTime($wo->dijadwalkan_selesai),
                    'aktual_mulai' => $this->dateTime($wo->aktual_mulai),
                    'aktual_selesai' => $this->dateTime($wo->aktual_selesai),
                    'dibuat_pada' => $this->dateTime($wo->created_at),
                ])->all(),
            ],
            fileName: 'daftar-work-order-'.now()->format('Ymd-Hi'),
        );
    }

    private function dateTime(?string $value): ?string
    {
        return $value === null ? null : date('d/m/Y H:i', strtotime($value));
    }
}
