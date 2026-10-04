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
use Modules\Apperp\ManagementAset\Models\master\DowntimeReason;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\Downtime\AssetDowntime;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Downtime aset, satu baris per periode aset tidak dapat dipakai.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar downtime (`AssetDowntimeController::index`):
 * izin `management-aset.downtime-aset.read`, dan `OrganizationScope::asetQuery()` — legal entity dan unit
 * penanggung jawab **asetnya**, karena catatan downtime tidak punya kolom unit sendiri. Karena itu dataset
 * ini bersumber query yang menggabungkan downtime dengan asetnya (join dalam, asetnya tidak disaring
 * arsip), persis seperti `joined()` di controller itu.
 *
 * Lama downtime dihitung hanya untuk catatan yang sudah ditutup. Catatan yang masih terbuka dihitung
 * layar daftar sampai saat ini, tetapi nilai yang bergantung pada jam pembacaan tidak cocok untuk hasil
 * yang disimpan di cache; catatan terbuka dapat dihitung terpisah lewat field "Masih berhenti".
 *
 * Alasan tanpa tanda "masuk KPI" tetap dihitung, seperti registrasi tanpa reason code di F&O.
 */
final class DowntimeDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $downtime = 'aset_tr_downtime_aset';

        return DatasetDefinition::make('management-aset.downtime', 'Downtime aset')
            ->description('Satu baris per periode aset berhenti dipakai: asetnya, alasannya, dan lamanya.')
            ->fromQuery(static fn () => SourceQuery::from(AssetDowntime::class)
                ->join('aset_tr_aset as aset', static function (JoinClause $join) use ($downtime): void {
                    $join->on('aset.id', '=', $downtime.'.aset_id')->on('aset.tenant_id', '=', $downtime.'.tenant_id');
                })
                ->leftJoin('aset_m_alasan_downtime as reason', static function (JoinClause $join) use ($downtime): void {
                    $join->on('reason.id', '=', $downtime.'.alasan_downtime_id')->on('reason.tenant_id', '=', $downtime.'.tenant_id');
                })
                ->select([
                    $downtime.'.tenant_id',
                    'aset.legal_entity_id',
                    'aset.responsible_org_unit_id',
                    'aset.id as asset_id',
                    'aset.group_aset_id',
                    'aset.jenis_aset_id',
                    $downtime.'.mulai as started_at',
                    $downtime.'.selesai as ended_at',
                    $downtime.'.sumber as source',
                    $downtime.'.alasan_downtime_id as reason_id',
                ])
                ->selectRaw('coalesce(reason.masuk_kpi, true) as counts_in_kpi')
                ->selectRaw('aset_tr_downtime_aset.selesai is null as is_open')
                ->selectRaw('extract(epoch from (aset_tr_downtime_aset.selesai - aset_tr_downtime_aset.mulai)) / 3600 as duration_hours'))
            ->permission('management-aset.downtime-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('jenis_aset_id', 'Jenis aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('started_at', 'Mulai berhenti', FieldType::DateTime, classification: DataClass::CustomerContent)
            ->field('ended_at', 'Selesai berhenti', FieldType::DateTime, classification: DataClass::CustomerContent)
            ->field('source', 'Sumber catatan', FieldType::Option, options: [AssetDowntime::MANUAL => 'Dicatat manual', AssetDowntime::WORK_ORDER => 'Dari work order'], classification: DataClass::CustomerContent)
            ->field('reason_id', 'Alasan downtime', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('counts_in_kpi', 'Dihitung dalam KPI', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->field('is_open', 'Masih berhenti', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->reference('asset_id', Aset::class)
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->reference('reason_id', DowntimeReason::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('started_at', default: true)
            ->time('ended_at')
            ->measure('count', 'Jumlah kejadian downtime', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset yang berhenti', Aggregate::CountDistinct, field: 'asset_id')
            ->measure('duration_hours', 'Lama downtime (jam)', Aggregate::Sum, field: 'duration_hours', format: MeasureFormat::Hours)
            ->measure('open_count', 'Downtime yang masih berjalan', Aggregate::Count, where: ['is_open' => true])
            ->measure('kpi_duration_hours', 'Lama downtime yang masuk KPI (jam)', Aggregate::Sum,
                field: 'duration_hours', format: MeasureFormat::Hours, where: ['counts_in_kpi' => true])
            ->version(1);
    }
}
