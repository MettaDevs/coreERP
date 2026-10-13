<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\MeasureFormat;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\FieldType;
use Modules\Apperp\ContohA\Models\Barang;
use Modules\Apperp\ContohA\Models\Penjualan;

/**
 * Dataset berkebijakan bahan uji engine analitik: memakai hampir setiap bagian kontrak dataset — kolom
 * kebijakan, join, rujukan berlabel, dimensi bersama, uang, measure bersaringan, dan tiga jenis kolom
 * waktu — supaya test engine tidak bergantung pada module aset.
 */
final class PenjualanDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'contoh-a';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('contoh-a.penjualan', 'Penjualan barang')
            ->description('Satu baris per penjualan barang.')
            ->model(Penjualan::class)
            ->permission('contoh-a.penjualan.read')
            ->dataPolicy('contoh-a.penjualan-unit', legalEntity: 'legal_entity_id', operatingUnit: 'org_unit_id')
            ->fieldsFromModel()
            ->join('barang', Barang::class, localColumn: 'barang_id')
            ->field('barang_bawaan', 'Barang bawaan', FieldType::Boolean, column: 'barang.bawaan')
            ->field('dicatat_oleh_user_id', 'Dicatat oleh', FieldType::Reference)
            ->reference('barang_id', Barang::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('org_unit_id', SharedDimension::OperatingUnit)
            ->shared('currency_code', SharedDimension::Currency)
            ->shared('dicatat_oleh_user_id', SharedDimension::User)
            ->hierarchy('legal_entity_unit', ['legal_entity_id', 'org_unit_id'])
            ->time('tanggal', default: true)
            ->time('dicatat_pada')
            ->time('dibayar_pada')
            ->measure('count', 'Jumlah penjualan', Aggregate::Count)
            ->measure('nilai', 'Nilai penjualan', Aggregate::Sum,
                field: 'nilai', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('rata_rata_nilai', 'Rata-rata nilai penjualan', Aggregate::Average,
                field: 'nilai', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('terbit', 'Penjualan terbit', Aggregate::Count, where: ['status' => ['terbit']])
            ->measure('barang_terjual', 'Barang berbeda yang terjual', Aggregate::CountDistinct, field: 'barang_id')
            ->version(1);
    }
}
