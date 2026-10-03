<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FieldType;
use Illuminate\Database\Query\JoinClause;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\Warranty\AssetWarranty;

/**
 * Garansi aset, satu baris per garansi.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar garansi (`AssetWarrantyController::index`):
 * izin `management-aset.garansi-aset.read`, dan `OrganizationScope::asetQuery()` — legal entity dan unit
 * penanggung jawab **asetnya**, karena garansi menempel pada aset dan tidak punya kolom unit sendiri. Karena
 * itu dataset ini bersumber query yang menggabungkan garansi dengan asetnya (join dalam, asetnya tidak
 * disaring arsip), persis seperti `joined()` di controller itu.
 *
 * Garansi tidak menyimpan nilai uang, jadi measure-nya hanya hitungan.
 */
final class WarrantiesDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $warranties = 'aset_tr_garansi_aset';

        return DatasetDefinition::make('management-aset.warranties', 'Garansi aset')
            ->description('Satu baris per garansi aset: asetnya, penjamin, jenis garansi, dan masa berlakunya.')
            ->fromQuery(static fn () => SourceQuery::from(AssetWarranty::class)
                ->join('aset_tr_aset as aset', static function (JoinClause $join) use ($warranties): void {
                    $join->on('aset.id', '=', $warranties.'.aset_id')->on('aset.tenant_id', '=', $warranties.'.tenant_id');
                })
                ->select([
                    $warranties.'.tenant_id',
                    'aset.legal_entity_id',
                    'aset.responsible_org_unit_id',
                    'aset.id as asset_id',
                    'aset.group_aset_id',
                    'aset.jenis_aset_id',
                    $warranties.'.vendor_id',
                    $warranties.'.jenis_garansi as warranty_type',
                    $warranties.'.berlaku_mulai as valid_from',
                    $warranties.'.berlaku_sampai as valid_until',
                ]))
            ->permission('management-aset.garansi-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('jenis_aset_id', 'Jenis aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('vendor_id', 'Penjamin', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('warranty_type', 'Jenis garansi', FieldType::Option, options: AssetWarranty::TYPES, classification: DataClass::CustomerContent)
            ->field('valid_from', 'Berlaku mulai', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('valid_until', 'Berlaku sampai', FieldType::Date, classification: DataClass::CustomerContent)
            ->reference('asset_id', Aset::class)
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->shared('vendor_id', SharedDimension::Vendor)
            ->time('valid_until', default: true)
            ->time('valid_from')
            ->measure('count', 'Jumlah garansi', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset bergaransi', Aggregate::CountDistinct, field: 'asset_id')
            ->version(1);
    }
}
