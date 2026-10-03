<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Register aset sebagai dataset analitik: satu baris per aset tercatat, termasuk komponen.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar aset (`AsetController::index`): izin
 * `management-aset.aset.read`, dan `OrganizationScope::asetQuery()` yang mencocokkan legal entity dan
 * **unit penanggung jawab** — bukan unit dimensi keuangan. Salah pilih kolom berarti kepala unit melihat
 * aset unit lain.
 *
 * Isi kerangka berjalan engine analitik (area 0): status, group, mata uang, tanggal perolehan, jumlah
 * aset, dan nilai perolehan. Area 5 melengkapinya dengan field lain, rujukan berlabel, dimensi bersama,
 * dan measure bersaringan.
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
            ->model(Aset::class)
            ->permission('management-aset.aset.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->fieldsFromModel(only: ['lifecycle_state', 'group_aset_id', 'currency_code', 'acquired_on'])
            ->time('acquired_on', default: true)
            ->measure('count', 'Jumlah aset', Aggregate::Count)
            ->measure('acquisition_value', 'Nilai perolehan', Aggregate::Sum,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code');
    }
}
