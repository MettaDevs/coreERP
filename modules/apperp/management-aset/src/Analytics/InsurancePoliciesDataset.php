<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Analytics;

use App\Platform\Modules\Contracts\Analytics\Aggregate;
use App\Platform\Modules\Contracts\Analytics\Dataset;
use App\Platform\Modules\Contracts\Analytics\DatasetDefinition;
use App\Platform\Modules\Contracts\Analytics\SharedDimension;
use App\Platform\Modules\Contracts\FieldType;
use Modules\Apperp\ManagementAset\Models\master\InsuranceType;
use Modules\Apperp\ManagementAset\Models\transaksi\Insurance\InsurancePolicy;

/**
 * Polis asuransi aset, satu baris per polis.
 *
 * Permission dan kolom kebijakannya sama dengan layar daftar polis (`InsurancePolicyController::index`):
 * izin `management-aset.polis-asuransi.read`, dan `OrganizationScope::legalEntityQuery()` — **hanya legal
 * entity**, tanpa unit. Polis milik entitas legal, bukan milik satu unit, jadi cukup hibah pada entitas
 * legalnya, unit mana pun. Karena itu `dataPolicy()` di sini tidak menyebut kolom unit; yang mencocokkan
 * unit akan menyembunyikan polis dari pengguna yang layar polisnya menampilkannya.
 *
 * Premi tahunan dan nilai pertanggungan sengaja belum menjadi measure. Tabel polis tidak menyimpan mata
 * uang, dan uang tidak boleh dijumlah tanpa mata uang (KA-22); dua entitas legal berbeda mata uang akan
 * tercampur diam-diam. Measure uangnya menunggu kolom mata uang pada polis.
 *
 * Nama polis dan nomor polis dikecualikan dari field: pengenal berkardinalitas tinggi, bukan pengelompok.
 */
final class InsurancePoliciesDataset implements Dataset
{
    public function moduleId(): string
    {
        return 'management-aset';
    }

    public function definition(): DatasetDefinition
    {
        return DatasetDefinition::make('management-aset.insurance-policies', 'Polis asuransi')
            ->description('Satu baris per polis asuransi aset: jenis, penanggung, masa berlaku, dan status blokirnya.')
            ->model(InsurancePolicy::class)
            ->permission('management-aset.polis-asuransi.read')
            ->dataPolicy('management-aset.asset-responsibility', legalEntity: 'legal_entity_id')
            ->field('kode', 'Kode polis', FieldType::Text)
            ->field('jenis_asuransi_id', 'Jenis asuransi', FieldType::Reference)
            ->field('vendor_id', 'Penanggung', FieldType::Reference)
            ->field('berlaku_mulai', 'Berlaku mulai', FieldType::Date)
            ->field('berlaku_sampai', 'Berlaku sampai', FieldType::Date)
            ->field('diblokir', 'Diblokir', FieldType::Boolean)
            ->reference('jenis_asuransi_id', InsuranceType::class)
            ->shared('legal_entity_id', SharedDimension::LegalEntity)
            ->shared('vendor_id', SharedDimension::Vendor)
            ->time('berlaku_sampai', default: true)
            ->time('berlaku_mulai')
            ->measure('count', 'Jumlah polis', Aggregate::Count)
            ->version(1);
    }
}
