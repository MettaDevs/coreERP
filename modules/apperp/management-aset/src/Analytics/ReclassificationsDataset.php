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
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassification;
use Modules\Apperp\ManagementAset\Models\transaksi\Reclassification\AssetReclassificationLine;

/**
 * Reklasifikasi aset, satu baris per aset asal pada satu dokumen reklasifikasi (pindah group atau pecah aset).
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar reklasifikasi
 * (`AssetReclassificationController::index`): izin `management-aset.reklasifikasi-aset.read`, dan
 * `OrganizationScope::query()` pada `legal_entity_id` dan `responsible_org_unit_id` milik **header**
 * dokumen. Nilai yang dipindah ada di baris, dan mata uangnya mata uang aset asal, jadi dataset ini
 * bersumber query yang menggabungkan baris, header (yang terarsip tidak ikut), dan aset asal.
 *
 * Group asal dan nilai yang dipindah kosong selama dokumen draf dan dibekukan saat diposting; ukuran
 * nilainya karena itu hanya bermakna untuk dokumen yang sudah diposting.
 */
final class ReclassificationsDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $lines = 'aset_tr_reklasifikasi_aset_details';

        return DatasetDefinition::make('management-aset.reclassifications', 'Reklasifikasi aset')
            ->description('Satu baris per aset asal pada dokumen reklasifikasi: group asal dan tujuan, serta nilai yang dipindah.')
            ->fromQuery(static fn () => SourceQuery::from(AssetReclassificationLine::class)
                ->join('aset_tr_reklasifikasi_aset as reclassification', static function (JoinClause $join) use ($lines): void {
                    $join->on('reclassification.id', '=', $lines.'.reklasifikasi_aset_id')->on('reclassification.tenant_id', '=', $lines.'.tenant_id');
                })
                ->whereNull('reclassification.deleted_at')
                ->leftJoin('aset_tr_aset as aset', static function (JoinClause $join) use ($lines): void {
                    $join->on('aset.id', '=', $lines.'.aset_id')->on('aset.tenant_id', '=', $lines.'.tenant_id');
                })
                ->select([
                    $lines.'.tenant_id',
                    'reclassification.kode as document_number',
                    'reclassification.legal_entity_id',
                    'reclassification.responsible_org_unit_id',
                    'reclassification.jenis as kind',
                    'reclassification.status',
                    'reclassification.tanggal as document_date',
                    $lines.'.aset_id as asset_id',
                    $lines.'.group_aset_asal_id as source_group_id',
                    $lines.'.group_aset_tujuan_id as target_group_id',
                    'aset.currency_code',
                    $lines.'.nilai_perolehan_dipindah as moved_value',
                ]))
            ->permission('management-aset.reklasifikasi-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('document_number', 'Nomor dokumen', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('kind', 'Jenis reklasifikasi', FieldType::Option, options: AssetReclassification::KINDS, classification: DataClass::CustomerContent)
            ->field('status', 'Status dokumen', FieldType::Option, options: [AssetReclassification::DRAFT => 'Draf', AssetReclassification::POSTED => 'Sudah diposting'], classification: DataClass::CustomerContent)
            ->field('document_date', 'Tanggal dokumen', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset asal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('source_group_id', 'Group asal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('target_group_id', 'Group tujuan', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->reference('asset_id', Aset::class)
            ->reference('source_group_id', GroupAset::class)
            ->reference('target_group_id', GroupAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('document_date', default: true)
            ->measure('count', 'Jumlah baris reklasifikasi', Aggregate::Count)
            ->measure('document_count', 'Jumlah dokumen', Aggregate::CountDistinct, field: 'document_number')
            ->measure('moved_value', 'Nilai perolehan yang dipindah', Aggregate::Sum,
                field: 'moved_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
