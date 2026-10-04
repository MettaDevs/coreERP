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
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\DepreciationPeriod;

/**
 * Riwayat penyusutan: satu baris per periode penyusutan sebuah buku aset, termasuk baris pembalik.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar penyusutan (`DepreciationController::index`):
 * izin `management-aset.penyusutan.read`, dan `OrganizationScope::query()` pada `legal_entity_id` dan
 * **`usage_org_unit_id`** milik periode itu sendiri — unit pengguna aset pada periodenya, bukan unit
 * penanggung jawab. Dua kolom ini disalin ke baris periode justru supaya penyaringan tidak menelusuri aset.
 *
 * Periode tidak punya kolom mata uang; mata uangnya mata uang aset. Karena itu dataset ini bersumber query
 * yang menggabungkan periode, buku aset, dan aset — join yang sama dengan daftarnya, tanpa saringan arsip
 * pada aset (aset yang sudah diarsipkan tetap punya riwayat penyusutan).
 *
 * Baris pembalik menyimpan jumlah bertanda negatif, jadi penjumlahan `amount` otomatis bersih dari
 * pembalikan. Periode berstatus usulan ikut terhitung; saring menurut status bila hanya yang final.
 */
final class DepreciationEntriesDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $periods = 'aset_tr_penyusutan_aset';

        return DatasetDefinition::make('management-aset.depreciation-entries', 'Riwayat penyusutan')
            ->description('Satu baris per periode penyusutan sebuah buku aset, termasuk pembalikannya.')
            ->fromQuery(static fn () => SourceQuery::from(DepreciationPeriod::class)
                ->join('aset_tr_buku_aset as book', static function (JoinClause $join) use ($periods): void {
                    $join->on('book.id', '=', $periods.'.buku_aset_id')->on('book.tenant_id', '=', $periods.'.tenant_id');
                })
                ->join('aset_tr_aset as aset', static function (JoinClause $join): void {
                    $join->on('aset.id', '=', 'book.aset_id')->on('aset.tenant_id', '=', 'book.tenant_id');
                })
                ->select([
                    $periods.'.tenant_id',
                    $periods.'.legal_entity_id',
                    $periods.'.usage_org_unit_id',
                    $periods.'.period_starts_on',
                    $periods.'.period_ends_on',
                    $periods.'.amount',
                    $periods.'.status',
                    'book.book_code',
                    'book.buku_id as book_id',
                    'aset.id as asset_id',
                    'aset.group_aset_id',
                    'aset.jenis_aset_id',
                    'aset.currency_code',
                ])
                ->selectRaw('aset_tr_penyusutan_aset.reverses_period_id is not null as is_reversal')
                ->selectRaw('aset_tr_penyusutan_aset.posted_posting_id is not null as is_posted'))
            ->permission('management-aset.penyusutan.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'usage_org_unit_id')
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('usage_org_unit_id', 'Unit pengguna', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('period_starts_on', 'Awal periode', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('period_ends_on', 'Akhir periode', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('status', 'Status periode', FieldType::Option, options: ['proposed' => 'Usulan', 'final' => 'Final'], classification: DataClass::CustomerContent)
            ->field('book_code', 'Kode buku', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('book_id', 'Buku penyusutan', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('jenis_aset_id', 'Jenis aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('is_reversal', 'Pembalikan', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->field('is_posted', 'Sudah di-post ke finance', FieldType::Boolean, classification: DataClass::CustomerContent)
            ->reference('book_id', BukuPenyusutan::class)
            ->reference('asset_id', Aset::class)
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('usage_org_unit_id', SharedDimension::OperatingUnit)
            ->time('period_ends_on', default: true)
            ->time('period_starts_on')
            ->measure('count', 'Jumlah periode', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset', Aggregate::CountDistinct, field: 'asset_id')
            ->measure('amount', 'Nilai penyusutan', Aggregate::Sum,
                field: 'amount', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
