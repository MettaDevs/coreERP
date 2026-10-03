<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Datasets\CompiledMeasure;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Analytics\Query\FieldUseGate;
use App\Platform\Modules\Contracts\DataClass;
use App\Platform\Modules\Contracts\FilterField;

/**
 * Langkah 5 urutan otorisasi (`docs/todo/analitik/keamanan.md` bagian *Data pribadi*): field berkelas
 * `EndUserIdentifiableInformation` — nama pasien, NIK, telepon, catatan medis — hanya untuk principal yang
 * boleh memakai data pribadi (`core.analytics.personal-data.read`). Publikasi dan embed tidak pernah boleh.
 *
 * Yang dijaga bukan hanya kolom yang tampil. Menyaring `nama_pembeli = 'Budi'` lalu membaca jumlahnya sama
 * dengan membaca datanya, jadi field tertutup ditolak sebagai pengelompok, saringan, kolom rentang waktu,
 * dan bahan measure — semua yang dilaporkan `QueryValidator` lewat {@see FieldUseGate}. Urutan ikut
 * terjaga karena `sort` hanya boleh memakai kunci yang sudah dipilih. Katalog untuk layar menyembunyikan
 * field dan measure yang sama ({@see self::visibleFields()}, {@see self::visibleMeasures()}).
 *
 * Klasifikasi dibaca dari dataset (`CompiledDataset::classification()`), yang mengambilnya dari klasifikasi
 * kolom model; gerbang ini tidak membuat klasifikasi sendiri. `EndUserPseudonymousIdentifiers` (id pengguna,
 * id pekerja) tetap boleh sebagai pengelompok; nama orang di balik id itu disembunyikan
 * `SharedDimensionRegistry::labels()` dengan hak yang sama dari principal.
 */
final class PersonalDataGate implements FieldUseGate
{
    public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void
    {
        if ($principal->mayUsePersonalData()) {
            return;
        }

        foreach ($uses as $path => $key) {
            if (self::isPersonal($dataset, $key)) {
                throw AnalyticsQueryException::fieldPersonalData($path, $dataset->filterField($key)->caption);
            }
        }
    }

    /**
     * Field yang boleh ditawarkan kepada principal ini di katalog dan pemilih kolom.
     *
     * @return array<string, FilterField>
     */
    public function visibleFields(CompiledDataset $dataset, AnalyticsPrincipal $principal): array
    {
        if ($principal->mayUsePersonalData()) {
            return $dataset->fields();
        }

        return array_filter($dataset->fields(), static fn (FilterField $field): bool => ! self::isPersonal($dataset, $field->key));
    }

    /**
     * Measure yang boleh ditawarkan kepada principal ini: yang kolom bahannya dan saringan tetapnya tidak
     * memakai field tertutup.
     *
     * @return array<string, CompiledMeasure>
     */
    public function visibleMeasures(CompiledDataset $dataset, AnalyticsPrincipal $principal): array
    {
        if ($principal->mayUsePersonalData()) {
            return $dataset->measures();
        }

        return array_filter($dataset->measures(), static function (CompiledMeasure $measure) use ($dataset): bool {
            foreach (self::measureFields($dataset, $measure) as $key) {
                if (self::isPersonal($dataset, $key)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Kunci field yang dibaca sebuah measure: kolom bahannya bila kolom itu field dataset, dan setiap field
     * saringan tetapnya — aturan yang sama dengan yang dilaporkan `QueryValidator` untuk measure terpilih.
     *
     * @return list<string>
     */
    private static function measureFields(CompiledDataset $dataset, CompiledMeasure $measure): array
    {
        $keys = $measure->field !== null && $dataset->hasField($measure->field) ? [$measure->field] : [];
        foreach (array_keys($measure->where) as $key) {
            if ($dataset->hasField($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private static function isPersonal(CompiledDataset $dataset, string $key): bool
    {
        return $dataset->classification($key) === DataClass::EndUserIdentifiableInformation;
    }
}
