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
use Modules\Apperp\ManagementAset\Models\master\GroupAset;
use Modules\Apperp\ManagementAset\Models\master\JenisAset;
use Modules\Apperp\ManagementAset\Models\transaksi\DokumenSiklusAset\DokumenSiklusAset;
use Modules\Apperp\ManagementAset\Models\transaksi\InventarisasiAset\Aset;

/**
 * Dasar dataset pelepasan aset: dokumen penjualan atau pemusnahan, satu baris per dokumen.
 *
 * Keduanya satu tabel (`aset_tr_dokumen_siklus_aset`) yang dibedakan `jenis_dokumen`, tetapi layar
 * daftarnya dijaga permission yang berbeda — `penjualan-aset.read` dan `pemusnahan-aset.read` — jadi
 * masing-masing menjadi dataset sendiri. Satu dataset berpermission tunggal akan memperlihatkan
 * pemusnahan kepada pengguna yang hanya boleh membaca penjualan.
 *
 * Kolom kebijakannya sama dengan `DokumenSiklusAsetController::index`: `OrganizationScope::query()` pada
 * `legal_entity_id` dan `responsible_org_unit_id` milik dokumen sendiri. Dokumen tidak punya mata uang; mata
 * uangnya mata uang aset yang dilepas, jadi dataset bersumber query yang menggabungkan dokumen dengan
 * asetnya (asetnya tidak disaring arsip, karena aset yang dilepas memang berakhir diarsipkan).
 *
 * Laba atau rugi pelepasan tidak tersimpan di tabel mana pun: ia dihitung saat pratinjau dan saat posting,
 * lalu hanya ikut ke jurnal. Karena itu belum ada measure laba/rugi di sini.
 */
abstract class DisposalDataset implements Dataset
{
    /** Nilai `jenis_dokumen` yang dilayani dataset ini. */
    abstract protected function documentType(): string;

    abstract protected function code(): string;

    abstract protected function caption(): string;

    abstract protected function description(): string;

    abstract protected function permission(): string;

    /** Hanya penjualan yang punya hasil; pemusnahan menolak nilai (`DisposalPosting::SCRAP_HAS_NO_PROCEEDS`). */
    abstract protected function hasProceeds(): bool;

    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        $type = $this->documentType();
        $documents = 'aset_tr_dokumen_siklus_aset';

        $definition = DatasetDefinition::make($this->code(), $this->caption())
            ->description($this->description())
            ->fromQuery(static fn () => SourceQuery::from(DokumenSiklusAset::class)
                ->where($documents.'.jenis_dokumen', $type)
                ->leftJoin('aset_tr_aset as aset', static function (JoinClause $join) use ($documents): void {
                    $join->on('aset.id', '=', $documents.'.aset_id')->on('aset.tenant_id', '=', $documents.'.tenant_id');
                })
                ->select([
                    $documents.'.tenant_id',
                    $documents.'.kode as document_number',
                    $documents.'.legal_entity_id',
                    $documents.'.responsible_org_unit_id',
                    $documents.'.tanggal as document_date',
                    $documents.'.status',
                    $documents.'.nilai as proceeds',
                    $documents.'.aset_id as asset_id',
                    'aset.group_aset_id',
                    'aset.jenis_aset_id',
                    'aset.currency_code',
                    'aset.acquisition_value',
                ]))
            ->permission($this->permission())
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id', operatingUnit: 'responsible_org_unit_id')
            ->field('document_number', 'Nomor dokumen', FieldType::Text, classification: DataClass::CustomerContent)
            ->field('legal_entity_id', 'Entitas legal', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('responsible_org_unit_id', 'Unit penanggung jawab', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('document_date', 'Tanggal dokumen', FieldType::Date, classification: DataClass::CustomerContent)
            ->field('status', 'Status dokumen', FieldType::Option, options: ['draft' => 'Draf', 'posted' => 'Sudah diposting', 'cancelled' => 'Dibatalkan'], classification: DataClass::CustomerContent)
            ->field('asset_id', 'Aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('group_aset_id', 'Group aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('jenis_aset_id', 'Jenis aset', FieldType::Reference, classification: DataClass::CustomerContent)
            ->field('currency_code', 'Mata uang', FieldType::Text, classification: DataClass::CustomerContent)
            ->reference('asset_id', Aset::class)
            ->reference('group_aset_id', GroupAset::class)
            ->reference('jenis_aset_id', JenisAset::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('responsible_org_unit_id', SharedDimension::OperatingUnit)
            ->time('document_date', default: true)
            ->measure('count', 'Jumlah dokumen', Aggregate::Count)
            ->measure('asset_count', 'Jumlah aset', Aggregate::CountDistinct, field: 'asset_id')
            ->measure('acquisition_value', 'Nilai perolehan aset', Aggregate::Sum,
                field: 'acquisition_value', format: MeasureFormat::Money, currency: 'currency_code')
            ->measure('posted', 'Dokumen yang sudah diposting', Aggregate::Count, where: ['status' => ['posted']]);

        if ($this->hasProceeds()) {
            $definition->measure('proceeds', 'Hasil penjualan', Aggregate::Sum,
                field: 'proceeds', format: MeasureFormat::Money, currency: 'currency_code');
            $definition->measure('posted_proceeds', 'Hasil penjualan yang sudah diposting', Aggregate::Sum,
                field: 'proceeds', format: MeasureFormat::Money, currency: 'currency_code', where: ['status' => ['posted']]);
        }

        return $definition->version(1);
    }
}
