<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use Modules\Apperp\ManagementAset\Models\master\TingkatLayanan;
use Modules\Apperp\ManagementAset\Models\master\TipeWorkOrder;
use Modules\Apperp\ManagementAset\Models\transaksi\PemeliharaanAset\PemeliharaanAset;
use Modules\Apperp\ManagementAset\Support\WorkOrderStatus;

/**
 * Work order pemeliharaan, satu baris per dokumen work order.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar work order (`PemeliharaanAsetController::index`):
 * izin `management-aset.pemeliharaan-aset.read`, dan `OrganizationScope::query()` pada `legal_entity_id` dan
 * `responsible_org_unit_id` milik header work order sendiri. Header tidak menyebut aset — asetnya ada di
 * baris pekerjaan — jadi kebijakannya tidak lewat join.
 *
 * Field datang dari katalog filter tambahan K-30 model. Penanggung jawab (data pribadi berkelas
 * pengenal pseudonim) dan keterangan bebas dikecualikan. Jadwal yang dibuat pengguna tersimpan tanpa zona
 * dan karena itu bukan field waktu; yang dipakai adalah saat dibuat dan saat pengerjaan benar-benar mulai
 * dan selesai, yang dicatat sistem dalam UTC.
 *
 * Jam kerja berada di baris pekerjaan, bukan di header, jadi dataset ini belum menjumlah jam. Dataset baris
 * pekerjaan (jam aktual per jenis pekerjaan atau group aset) belum dibuat di fase ini.
 */
final class WorkOrdersDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('management-aset.work-orders', 'Work order pemeliharaan')
            ->description('Satu baris per work order: status, tipe, tingkat layanan, dan waktu pengerjaannya.')
            ->model(PemeliharaanAset::class)
            ->permission('management-aset.pemeliharaan-aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->fieldsFromModel(except: ['keterangan', 'penanggung_jawab_user_id'])
            ->reference('tipe_work_order_id', TipeWorkOrder::class)
            ->reference('tingkat_layanan_id', TingkatLayanan::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('created_at', default: true)
            ->time('aktual_mulai')
            ->time('aktual_selesai')
            ->measure('count', 'Jumlah work order', Aggregate::Count)
            ->recordRoute('/management-aset/pemeliharaan-aset/{id}')
            ->measure('completed', 'Work order selesai', Aggregate::Count, where: ['status' => [WorkOrderStatus::SELESAI, WorkOrderStatus::DITUTUP]])
            ->measure('cancelled', 'Work order dibatalkan', Aggregate::Count, where: ['status' => [WorkOrderStatus::DIBATALKAN]])
            ->version(1);
    }
}
