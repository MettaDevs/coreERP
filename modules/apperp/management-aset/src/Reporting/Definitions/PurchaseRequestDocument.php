<?php

namespace Modules\Apperp\ManagementAset\Reporting\Definitions;

use Modules\Apperp\ManagementAset\Models\transaksi\PerencanaanAset\PerencanaanAsetDetail;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PermintaanPengadaanAset\PermintaanPengadaanAsetDetail;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportData;
use Modules\Apperp\ManagementAset\Reporting\ReportDataException;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Services\AssetOrganizationDirectory;
use Modules\Apperp\ManagementAset\Support\OrganizationScope;

final class PurchaseRequestDocument implements ReportDefinition
{
    public function code(): string
    {
        return 'purchase-request';
    }

    public function name(): string
    {
        return 'Permintaan pembelian aset';
    }

    public function description(): string
    {
        return 'Dokumen permintaan untuk dicetak, beserta perencanaan asal dan barang yang diminta.';
    }

    public function permission(): string
    {
        return 'management-aset.permintaan-pembelian-aset.read';
    }

    public function builtinLayouts(): array
    {
        return [new BuiltinLayout('standar', 'Permintaan pembelian aset (Word)', 'Kop, rincian barang, dan tempat tanda tangan.', 'docx')];
    }

    public function parameterRules(): array
    {
        return ['id' => ['required', 'ulid']];
    }

    public function dataItems(): array
    {
        return [];
    }

    public function fields(): array
    {
        $fields = [];
        foreach (['code' => 'No. permintaan', 'requested_on' => 'Tanggal permintaan', 'unit_name' => 'Unit peminta', 'requester_name' => 'Peminta', 'description' => 'Keterangan', 'status' => 'Status'] as $key => $label) {
            $fields[] = ['key' => $key, 'label' => $label, 'table' => null, ...($key === 'requested_on' ? ['type' => 'date'] : [])];
        }
        foreach (['number' => 'Baris', 'planning_code' => 'No. perencanaan', 'name' => 'Nama barang', 'unit' => 'Satuan', 'quantity' => 'Jumlah', 'specification' => 'Spesifikasi'] as $key => $label) {
            $fields[] = ['key' => 'lines.'.$key, 'label' => $label, 'table' => 'lines', ...($key === 'quantity' ? ['type' => 'number'] : [])];
        }

        return $fields;
    }

    public function data(ReportContext $context, array $parameters): ReportData
    {
        $query = PermintaanPengadaanAset::query()->whereKey($parameters['id']);
        app(OrganizationScope::class)->query($query, $context->request(), 'legal_entity_id', 'requesting_org_unit_id');
        $record = $query->first();
        if ($record === null) {
            throw new ReportDataException('Permintaan tidak ditemukan atau tidak dapat dibuka.');
        }
        $lines = PermintaanPengadaanAsetDetail::query()->where('request_id', $record->id)->orderBy('line_number')->get();
        $planningLines = PerencanaanAsetDetail::query()->with('perencanaan')->whereIn('id', $lines->pluck('planning_detail_id'))->get()->keyBy('id');
        $directory = app(AssetOrganizationDirectory::class);

        return new ReportData(
            fields: [
                'code' => $record->kode,
                'requested_on' => $record->requested_on->toDateString(),
                'unit_name' => $directory->unitName($context->tenantId, $record->requesting_org_unit_id),
                'requester_name' => $directory->personName($context->tenantId, $record->requester_user_id),
                'description' => $record->description,
                'status' => $record->status === 'cancelled' ? 'Dibatalkan' : 'Draf',
            ],
            tables: ['lines' => $lines->map(function ($line) use ($planningLines): array {
                $planLine = $planningLines->get($line->planning_detail_id);

                return [
                    'number' => $line->line_number,
                    'planning_code' => $planLine?->perencanaan?->kode,
                    'name' => $line->nama_aset,
                    'unit' => $planLine?->unit,
                    'quantity' => $line->quantity,
                    'specification' => $line->specification,
                ];
            })->all()],
            fileName: $record->kode,
        );
    }
}
