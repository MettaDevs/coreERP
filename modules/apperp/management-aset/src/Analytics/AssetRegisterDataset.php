<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\master\KondisiAset;
use Modules\Apperp\ManagementAset\Models\master\LokasiAset;
use Modules\Apperp\ManagementAset\Models\master\ModelAset;
use Modules\Apperp\ManagementAset\Models\master\PabrikanAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Register aset sebagai dataset analitik: satu baris per aset tercatat, termasuk komponen.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar aset (`AsetController::index`): izin
 * `management-aset.aset.read`, dan `OrganizationScope::asetQuery()` yang mencocokkan legal entity dan
 * **unit penanggung jawab** — bukan unit dimensi keuangan. Salah pilih kolom berarti kepala unit melihat
 * aset unit lain.
 *
 * Field datang dari katalog filter tambahan K-30 model aset, jadi nama tampilan dan pilihan status tidak
 * ditulis ulang di sini. Keterangan, nomor seri, dan nomor model dikecualikan: teks bebas berkardinalitas
 * tinggi dan tidak berguna sebagai pengelompok.
 *
 * Measure bersaringan (aset yang sudah dilepas) menunggu compiler area 3 — saringan tetap measure belum
 * dikompilasi, dan measure yang tidak dapat dijalankan tidak ditawarkan.
 */
final class AssetRegisterDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('management-aset.asset-register', 'Register aset')
            ->description('Satu baris per aset tercatat, termasuk komponen.')
            ->model(Aset::class)
            ->permission('management-aset.aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->fieldsFromModel(except: ['keterangan', 'serial_number', 'model_number'])
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->reference('kondisi_aset_id', KondisiAset::class)
            ->reference('lokasi_aset_id', LokasiAset::class)
            ->reference('pabrikan_aset_id', PabrikanAset::class)
            ->reference('model_aset_id', ModelAset::class)
            // `legal_entity_id` tersembunyi dari katalog filter ("dipilih lewat workspace"), tetapi untuk
            // analitik ia dimensi yang berguna; `shared()` membuatnya menjadi field.
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->shared('financial_dimension_org_unit_id', SharedDimension::OperatingUnit)
            ->time('acquired_on', default: true)
            ->time('placed_in_service_on')
            ->measure('count', 'Jumlah aset', Aggregate::Count)
            ->measure('acquisition_value', 'Nilai perolehan', Aggregate::Sum,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('average_acquisition_value', 'Rata-rata nilai perolehan', Aggregate::Average,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->recordRoute('/management-aset/inventarisasi-aset/{id}')
            ->version(1);
    }
}
