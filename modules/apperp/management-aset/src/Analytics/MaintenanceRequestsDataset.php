<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\FieldType;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\master\MaintenanceRequestType;
use Modules\Apperp\ManagementAset\Models\master\SebabKerusakan;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\MaintenanceRequest\MaintenanceRequest;
use Modules\Apperp\ManagementAset\Support\MaintenanceRequestStatus;

/**
 * Permintaan pemeliharaan, satu baris per permintaan.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar permintaan
 * (`MaintenanceRequestController::index`): izin `management-aset.permintaan-pemeliharaan.read`, dan
 * `OrganizationScope::query()` pada `legal_entity_id` dan `responsible_org_unit_id` — unit pelapor — milik
 * permintaan itu sendiri. Asetnya boleh kosong (permintaan atas lokasi), jadi kebijakannya tidak lewat aset.
 *
 * Model permintaan belum punya katalog filter K-30, jadi field dinyatakan satu per satu. Deskripsi,
 * alasan penolakan, dan pengguna yang memutuskan dikecualikan: teks bebas dan data pribadi.
 */
final class MaintenanceRequestsDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('management-aset.maintenance-requests', 'Permintaan pemeliharaan')
            ->description('Satu baris per permintaan pemeliharaan: status, jenis, aset atau lokasi, dan waktu pengajuannya.')
            ->model(MaintenanceRequest::class)
            ->permission('management-aset.permintaan-pemeliharaan.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('kode', 'Nomor permintaan', FieldType::Text)
            ->field('status', 'Status permintaan', FieldType::Option, options: MaintenanceRequestStatus::LABELS)
            ->field('jenis_permintaan_id', 'Jenis permintaan', FieldType::Reference)
            ->field('aset_id', 'Aset', FieldType::Reference)
            ->field('lokasi_aset_id', 'Lokasi', FieldType::Reference)
            ->field('tingkat_layanan_id', 'Tingkat layanan', FieldType::Reference)
            ->field('sebab_kerusakan_id', 'Sebab kerusakan', FieldType::Reference)
            ->field('created_at', 'Dibuat pada', FieldType::DateTime)
            ->field('diajukan_pada', 'Diajukan pada', FieldType::DateTime)
            ->field('diputuskan_pada', 'Diputuskan pada', FieldType::DateTime)
            ->reference('jenis_permintaan_id', MaintenanceRequestType::class)
            ->reference('aset_id', Aset::class)
            ->reference('lokasi_aset_id', LokasiAset::class)
            ->reference('tingkat_layanan_id', TingkatLayanan::class)
            ->reference('sebab_kerusakan_id', SebabKerusakan::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('created_at', default: true)
            ->time('diajukan_pada')
            ->time('diputuskan_pada')
            ->measure('count', 'Jumlah permintaan', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset yang dilaporkan', Aggregate::CountDistinct, field: 'aset_id')
            ->recordRoute('/management-aset/permintaan-pemeliharaan/{id}')
            ->measure('accepted', 'Permintaan diterima', Aggregate::Count,
                where: ['status' => [MaintenanceRequestStatus::ACCEPTED, MaintenanceRequestStatus::WORK_ORDER_CREATED]])
            ->measure('rejected', 'Permintaan ditolak', Aggregate::Count, where: ['status' => [MaintenanceRequestStatus::REJECTED]])
            ->version(1);
    }
}
