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
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustment;
use Modules\Apperp\ManagementAset\Models\transaksi\ValueAdjustment\AssetValueAdjustmentLine;

/**
 * Penyesuaian nilai aset, satu baris per aset pada satu dokumen penyesuaian (penurunan atau kenaikan nilai).
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar penyesuaian
 * (`AssetValueAdjustmentController::index`): izin `management-aset.penyesuaian-nilai-aset.read`, dan
 * `OrganizationScope::query()` pada `legal_entity_id` dan `responsible_org_unit_id` milik **header**
 * dokumen. Nilai ada di baris, header tidak punya total dan tidak punya mata uang, jadi dataset ini
 * bersumber query yang menggabungkan baris, header (yang terarsip tidak ikut), dan aset untuk mata uangnya.
 *
 * Nilai di baris selalu positif; arahnya dibawa jenis header. `net_effect` memberi tanda: kenaikan nilai
 * positif, penurunan nilai negatif, sehingga jumlahnya langsung dampak bersih ke nilai buku. Dokumen
 * berstatus draf ikut terhitung; saring menurut status bila hanya yang sudah diposting.
 */
final class ValueAdjustmentsDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $lines = 'aset_tr_penyesuaian_nilai_aset_details';

        return DatasetDefinition::make('management-aset.value-adjustments', 'Penyesuaian nilai aset')
            ->description('Satu baris per aset pada dokumen penurunan atau kenaikan nilai aset.')
            ->fromQuery(static fn () => SourceQuery::from(AssetValueAdjustmentLine::class)
                ->join('aset_tr_penyesuaian_nilai_aset as adjustment', static function (JoinClause $join) use ($lines): void {
                    $join->on('adjustment.id', '=', $lines.'.penyesuaian_nilai_aset_id')->on('adjustment.tenant_id', '=', $lines.'.tenant_id');
                })
                ->whereNull('adjustment.deleted_at')
                ->leftJoin('aset_tr_aset as aset', static function (JoinClause $join) use ($lines): void {
                    $join->on('aset.id', '=', $lines.'.aset_id')->on('aset.tenant_id', '=', $lines.'.tenant_id');
                })
                ->select([
                    $lines.'.tenant_id',
                    'adjustment.kode as document_number',
                    'adjustment.legal_entity_id',
                    'adjustment.responsible_org_unit_id',
                    'adjustment.jenis as kind',
                    'adjustment.status',
                    'adjustment.tanggal as document_date',
                    'adjustment.buku_id as book_id',
                    $lines.'.aset_id as asset_id',
                    'aset.group_aset_id',
                    'aset.currency_code',
                    $lines.'.nilai as amount',
                ])
                ->selectRaw('case when adjustment.jenis = ? then -aset_tr_penyesuaian_nilai_aset_details.nilai else aset_tr_penyesuaian_nilai_aset_details.nilai end as net_effect', [AssetValueAdjustment::WRITE_DOWN]))
            ->permission('management-aset.penyesuaian-nilai-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('document_number', 'Nomor dokumen', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('kind', 'Jenis penyesuaian', FieldType::Option, options: AssetValueAdjustment::KINDS, classification: DataClass::CustomerContent)
            ->field('status', 'Status dokumen', FieldType::Option, options: [AssetValueAdjustment::DRAFT => 'Draf', AssetValueAdjustment::POSTED => 'Sudah diposting'], classification: DataClass::CustomerContent)
            ->field('document_date', 'Tanggal dokumen', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('book_id', 'Buku penyusutan', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->reference('book_id', BukuPenyusutan::class)
            ->reference('asset_id', Aset::class)
            ->reference('group_aset_id', GroupAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('document_date', default: true)
            ->measure('count', 'Jumlah baris penyesuaian', Aggregate::Count)
            ->measure('document_count', 'Jumlah dokumen', Aggregate::CountDistinct, field: 'document_number')
            ->measure('amount', 'Nilai penyesuaian', Aggregate::Sum,
                field: 'amount', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('net_effect', 'Dampak ke nilai buku', Aggregate::Sum,
                field: 'net_effect', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
