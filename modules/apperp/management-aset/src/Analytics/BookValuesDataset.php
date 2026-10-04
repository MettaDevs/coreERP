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
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\BukuAset;

/**
 * Nilai buku aset: satu baris per pasangan aset dan buku penyusutan, dengan nilai perolehan, akumulasi
 * penyusutan, dan nilai buku saat ini.
 *
 * Tabel buku aset sudah menyimpan saldo terakhirnya — finalisasi dan pembalikan penyusutan serta posting
 * penyesuaian nilai memeliharanya — jadi tidak ada penentuan "baris terakhir" yang perlu dihitung. Baris
 * ini potret keadaan sekarang, bukan riwayat; riwayatnya ada di dataset riwayat penyusutan.
 *
 * Permission dan kolom kebijakannya sama dengan daftar buku di layar penyusutan
 * (`DepreciationController::books`): izin `management-aset.penyusutan.read`, dan
 * `OrganizationScope::asetQuery()` — legal entity dan **unit penanggung jawab asetnya**, karena buku tidak
 * punya kolom unit. Mata uangnya juga mata uang aset. Karena itu dataset ini bersumber query yang
 * menggabungkan buku dengan asetnya; asetnya tidak disaring arsip, seperti daftarnya.
 *
 * Daftar di layar hanya menawarkan buku aktif; dataset ini membawa keduanya, dan statusnya dapat disaring.
 */
final class BookValuesDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $books = 'aset_tr_buku_aset';

        return DatasetDefinition::make('management-aset.book-values', 'Nilai buku aset')
            ->description('Satu baris per aset dan buku penyusutan: nilai perolehan, akumulasi penyusutan, dan nilai buku saat ini.')
            ->fromQuery(static fn () => SourceQuery::from(BukuAset::class)
                ->join('aset_tr_aset as aset', static function (JoinClause $join) use ($books): void {
                    $join->on('aset.id', '=', $books.'.aset_id')->on('aset.tenant_id', '=', $books.'.tenant_id');
                })
                ->select([
                    $books.'.tenant_id',
                    'aset.legal_entity_id',
                    'aset.responsible_org_unit_id',
                    'aset.id as asset_id',
                    'aset.group_aset_id',
                    'aset.jenis_aset_id',
                    'aset.currency_code',
                    $books.'.book_code',
                    $books.'.buku_id as book_id',
                    $books.'.status',
                    $books.'.depreciation_start_on',
                    $books.'.acquisition_value',
                    $books.'.accumulated_depreciation',
                    $books.'.net_book_value',
                ]))
            ->permission('management-aset.penyusutan.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('jenis_aset_id', 'Jenis aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('book_code', 'Kode buku', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('book_id', 'Buku penyusutan', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('status', 'Status buku', FieldType::Option, options: ['active' => 'Aktif', 'closed' => 'Ditutup'], classification: DataClass::CustomerContent)
            ->field('depreciation_start_on', 'Tanggal mulai penyusutan', FieldType::Date, classification: DataClass::CustomerContent)
            ->reference('asset_id', Aset::class)
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->reference('book_id', BukuPenyusutan::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('depreciation_start_on')
            ->measure('count', 'Jumlah buku aset', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset', Aggregate::CountDistinct, field: 'asset_id')
            ->measure('acquisition_value', 'Nilai perolehan', Aggregate::Sum,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('accumulated_depreciation', 'Akumulasi penyusutan', Aggregate::Sum,
                field: 'accumulated_depreciation', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('net_book_value', 'Nilai buku', Aggregate::Sum,
                field: 'net_book_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->version(1);
    }
}
