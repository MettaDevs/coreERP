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
use Modules\Apperp\ManagementAset\Models\master\BukuPenyusutan;
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;
use Modules\Apperp\ManagementAset\Models\transaksi\Ledger\FixedAssetLedgerEntry;

final class FixedAssetLedgerDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $definition = DatasetDefinition::make('management-aset.fixed-asset-ledger', 'Transaksi nilai aset')
            ->description('Perolehan, penyusutan final, penyesuaian, reklasifikasi, pelepasan, dan pembalikan per buku aset.')
            ->model(FixedAssetLedgerEntry::class)
            ->permission('management-aset.penyusutan.read')
            ->company('legal_entity_id')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->partition('book_code')
            ->join('asset_book', BukuAset::class, 'buku_aset_id', includeArchived: true);
        foreach ([
            'id' => ['Id transaksi', FieldType::Text],
            'asset_code' => ['Kode aset', FieldType::Text], 'asset_name' => ['Nama aset', FieldType::Text],
            'aset_id' => ['Aset', FieldType::Reference], 'buku_aset_id' => ['Buku aset', FieldType::Reference],
            'group_aset_id' => ['Group aset', FieldType::Reference], 'book_code' => ['Buku penyusutan', FieldType::Text],
            'currency_code' => ['Mata uang', FieldType::Text], 'posting_date' => ['Tanggal transaksi', FieldType::Date],
            'document_number' => ['Nomor dokumen', FieldType::Text], 'description' => ['Keterangan', FieldType::Text],
            'source_type' => ['Jenis dokumen', FieldType::Text], 'source_id' => ['Id dokumen', FieldType::Text],
            'legal_entity_id' => ['Entitas legal', FieldType::Reference], 'responsible_org_unit_id' => ['Unit penanggung jawab', FieldType::Reference],
            'is_reconstructed' => ['Data rekonstruksi', FieldType::Boolean],
        ] as $key => [$caption, $type]) {
            $definition->field($key, $caption, $type, classification: DataClass::CustomerContent);
        }
        $definition->field('posting_type', 'Jenis transaksi', FieldType::Option, options: [
            'acquisition' => 'Perolehan', 'opening_balance' => 'Saldo awal', 'acquisition_correction' => 'Koreksi perolehan',
            'depreciation' => 'Penyusutan', 'write_down' => 'Penurunan nilai', 'appreciation' => 'Kenaikan nilai',
            'reclassification' => 'Reklasifikasi', 'disposal' => 'Pelepasan', 'reversal' => 'Pembalikan', 'correction' => 'Koreksi',
        ], classification: DataClass::CustomerContent);
        foreach ([
            'amount' => 'Perubahan nilai buku', 'acquisition_delta' => 'Perubahan nilai perolehan',
            'accumulated_delta' => 'Perubahan akumulasi penyusutan', 'write_down_delta' => 'Perubahan penurunan nilai',
            'appreciation_delta' => 'Perubahan kenaikan nilai',
        ] as $key => $caption) {
            $definition->field($key, $caption, FieldType::Number, classification: DataClass::CustomerContent)
                ->measure('sum_'.$key, $caption, Aggregate::Sum, field: $key, format: MeasureFormat::Money, currency: 'currency_code');
            if ($key !== 'amount') {
                $definition->measure('minimum_'.$key, 'Minimum '.$caption, Aggregate::Minimum, field: $key, format: MeasureFormat::Money, currency: 'currency_code')
                    ->measure('maximum_'.$key, 'Maximum '.$caption, Aggregate::Maximum, field: $key, format: MeasureFormat::Money, currency: 'currency_code')
                    ->measure('average_'.$key, 'Average '.$caption, Aggregate::Average, field: $key, format: MeasureFormat::Money, currency: 'currency_code');
            }
        }

        return $definition->reference('group_aset_id', GroupAset::class)
            ->reference('buku_aset_id', BukuPenyusutan::class, localColumn: 'asset_book.buku_id')
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('posting_date', default: true)
            ->measure('count', 'Jumlah transaksi', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset', Aggregate::CountDistinct, field: 'aset_id')
            ->measure('average_amount', 'Rata-rata perubahan nilai buku', Aggregate::Average, field: 'amount', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('minimum_amount', 'Perubahan nilai buku terkecil', Aggregate::Minimum, field: 'amount', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('maximum_amount', 'Perubahan nilai buku terbesar', Aggregate::Maximum, field: 'amount', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
