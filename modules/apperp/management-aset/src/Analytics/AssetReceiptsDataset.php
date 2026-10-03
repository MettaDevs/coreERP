<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\PenerimaanAset\PenerimaanAsetDetail;
use Modules\Apperp\ManagementAset\Support\AcquisitionMethod;

/**
 * Penerimaan aset, satu baris per baris dokumen penerimaan: barang yang datang, vendornya, dan nilainya.
 *
 * Nilai penerimaan tidak tersimpan sebagai kolom — header dokumen tidak punya total, dan baris menyimpan
 * `jumlah` serta `nilai_per_unit` — jadi dataset ini bersumber query yang menghitung `jumlah × nilai_per_unit`.
 * Join ke header disusun di dalam query sumber, dan mata uangnya mata uang header.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar penerimaan (`PenerimaanAsetController::index`):
 * izin `management-aset.penerimaan-aset.read`, dan `OrganizationScope::query()` pada legal entity dan unit
 * penanggung jawab **header** dokumen. Baris tidak punya unit sendiri; ia mengikuti headernya, dan header
 * terarsip tidak ikut, persis seperti daftarnya.
 */
final class AssetReceiptsDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $details = 'aset_tr_penerimaan_aset_details';

        return DatasetDefinition::make('management-aset.asset-receipts', 'Penerimaan aset')
            ->description('Satu baris per baris dokumen penerimaan aset: barang yang datang, vendor, dan nilainya.')
            ->fromQuery(static fn () => SourceQuery::from(PenerimaanAsetDetail::class)
                ->join('aset_tr_penerimaan_aset as receipt', static function (JoinClause $join) use ($details): void {
                    $join->on('receipt.id', '=', $details.'.penerimaan_aset_id')->on('receipt.tenant_id', '=', $details.'.tenant_id');
                })
                ->whereNull('receipt.deleted_at')
                ->select([
                    $details.'.tenant_id',
                    'receipt.kode as receipt_number',
                    'receipt.legal_entity_id',
                    'receipt.responsible_org_unit_id',
                    'receipt.vendor_id',
                    'receipt.status',
                    'receipt.cara_perolehan as acquisition_method',
                    'receipt.tanggal as received_on',
                    'receipt.currency_code',
                    $details.'.group_aset_id',
                    $details.'.jenis_aset_id',
                    $details.'.jumlah as quantity',
                ])
                ->selectRaw('aset_tr_penerimaan_aset_details.jumlah * aset_tr_penerimaan_aset_details.nilai_per_unit as line_value'))
            ->permission('management-aset.penerimaan-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('receipt_number', 'Nomor penerimaan', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('vendor_id', 'Vendor', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('status', 'Status penerimaan', FieldType::Option, options: ['draft' => 'Draf', 'selesai' => 'Selesai'], classification: DataClass::CustomerContent)
            ->field('acquisition_method', 'Cara perolehan', FieldType::Option, options: AcquisitionMethod::LABELS, classification: DataClass::CustomerContent)
            ->field('received_on', 'Tanggal penerimaan', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('jenis_aset_id', 'Jenis aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->shared('vendor_id', SharedDimension::Vendor)
            ->time('received_on', default: true)
            ->measure('count', 'Jumlah baris penerimaan', Aggregate::Count)
            ->measure('receipt_count', 'Jumlah dokumen penerimaan', Aggregate::CountDistinct, field: 'receipt_number')
            ->measure('quantity', 'Jumlah unit diterima', Aggregate::Sum, field: 'quantity')
            ->measure('receipt_value', 'Nilai penerimaan (belum termasuk PPN)', Aggregate::Sum,
                field: 'line_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
