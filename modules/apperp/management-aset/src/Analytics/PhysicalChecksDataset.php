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
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MonitoringAset\AssetMonitoringLine;
use Modules\Apperp\ManagementAset\Support\AssetMonitoringStatus;

/**
 * Pemeriksaan fisik aset (monitoring aset), satu baris per aset pada satu pemeriksaan.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar monitoring (`AssetMonitoringController::index`):
 * izin `management-aset.monitoring-aset.read`, dan `OrganizationScope::query()` pada `legal_entity_id` dan
 * `responsible_org_unit_id` milik **header** pemeriksaan. Unit di header boleh kosong; pemeriksaan tanpa
 * unit hanya terlihat bagi yang menjangkau seluruh organisasi, sama dengan layar dan tanpa pengecualian di
 * sini. Temuan ada di baris, jadi dataset ini bersumber query yang menggabungkan baris, header (yang
 * terarsip tidak ikut), dan aset untuk mata uangnya.
 *
 * Nilai perolehan, akumulasi penyusutan, dan nilai buku di baris dibekukan saat pemeriksaan diselesaikan
 * dan kosong selama draf; ukuran uangnya karena itu hanya bermakna untuk pemeriksaan yang sudah selesai.
 */
final class PhysicalChecksDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $lines = 'aset_tr_monitoring_aset_details';

        return DatasetDefinition::make('management-aset.physical-checks', 'Pemeriksaan fisik aset')
            ->description('Satu baris per aset pada pemeriksaan fisik: ada atau tidaknya, kondisinya, dan kesesuaian dengan catatan.')
            ->fromQuery(static fn () => SourceQuery::from(AssetMonitoringLine::class)
                ->join('aset_tr_monitoring_aset as monitoring', static function (JoinClause $join) use ($lines): void {
                    $join->on('monitoring.id', '=', $lines.'.monitoring_aset_id')->on('monitoring.tenant_id', '=', $lines.'.tenant_id');
                })
                ->whereNull('monitoring.deleted_at')
                ->leftJoin('aset_tr_aset as aset', static function (JoinClause $join) use ($lines): void {
                    $join->on('aset.id', '=', $lines.'.aset_id')->on('aset.tenant_id', '=', $lines.'.tenant_id');
                })
                ->select([
                    $lines.'.tenant_id',
                    'monitoring.kode as document_number',
                    'monitoring.legal_entity_id',
                    'monitoring.responsible_org_unit_id',
                    'monitoring.status',
                    'monitoring.tanggal as check_date',
                    'monitoring.lokasi_aset_id as checked_location_id',
                    $lines.'.aset_id as asset_id',
                    $lines.'.ada as is_present',
                    $lines.'.kondisi_aset_id as found_condition_id',
                    $lines.'.hasil as result',
                    'aset.group_aset_id',
                    'aset.currency_code',
                    $lines.'.nilai_perolehan as acquisition_value',
                    $lines.'.akumulasi_penyusutan as accumulated_depreciation',
                    $lines.'.nilai_buku as book_value',
                ]))
            ->permission('management-aset.monitoring-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('document_number', 'Nomor pemeriksaan', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('status', 'Status pemeriksaan', FieldType::Option, options: [AssetMonitoringStatus::DRAFT => 'Draf', AssetMonitoringStatus::COMPLETED => 'Selesai'], classification: DataClass::CustomerContent)
            ->field('check_date', 'Tanggal pemeriksaan', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('checked_location_id', 'Lokasi diperiksa', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('is_present', 'Ada secara fisik', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->field('found_condition_id', 'Kondisi fisik', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('result', 'Hasil pemeriksaan', FieldType::Option, options: [AssetMonitoringStatus::MATCH => 'Sesuai', AssetMonitoringStatus::MISMATCH => 'Tidak sesuai'], classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->reference('checked_location_id', LokasiAset::class)
            ->reference('asset_id', Aset::class)
            ->reference('found_condition_id', KondisiAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('check_date', default: true)
            ->measure('count', 'Jumlah baris pemeriksaan', Aggregate::Count)
            ->measure('document_count', 'Jumlah pemeriksaan', Aggregate::CountDistinct, field: 'document_number')
            ->measure('asset_count', 'Jumlah aset diperiksa', Aggregate::CountDistinct, field: 'asset_id')
            ->measure('acquisition_value', 'Nilai perolehan', Aggregate::Sum,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('accumulated_depreciation', 'Akumulasi penyusutan', Aggregate::Sum,
                field: 'accumulated_depreciation', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('book_value', 'Nilai buku', Aggregate::Sum,
                field: 'book_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
