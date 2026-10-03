<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

/**
 * Sidik jari jangkauan satu principal atas satu dataset: hash dari semua yang menentukan **baris mana**
 * yang terlihat dan **label mana** yang boleh tampil, untuk kunci cache hasil (area 9).
 *
 * Isinya tenant, hibah kebijakan data dataset itu (terurut), hak data pribadi, dan saringan terkunci
 * (terurut). Bukan id pengguna: dua pengguna dengan hibah sama berbagi cache, dua pengguna dengan hibah
 * berbeda tidak pernah. Tenant ikut walaupun kunci cache sudah memuatnya, supaya dua tenant yang sama-sama
 * memegang jangkauan penuh tidak pernah bersidik jari sama bila suatu hari kunci itu disusun tanpa tenant.
 *
 * Normalisasinya sengaja hanya mengurutkan, tidak menggabungkan hibah: hibah `A` dan `B` terpisah memang
 * menjangkau baris yang sama dengan satu hibah `A, B`, tetapi aturan penggabungan yang keliru sekali saja
 * membuat dua jangkauan berbeda berbagi cache. Kehilangan sedikit pembagian cache jauh lebih murah.
 */
final class ScopeFingerprint
{
    /** Versi bentuk masukan hash; naikkan bila isinya berubah, supaya sidik jari lama tidak pernah cocok. */
    private const VERSION = 1;

    /**
     * `$policyCode` adalah kebijakan data yang dinyatakan dataset, atau null bila dataset tidak dibatasi
     * kebijakan — hibah principal lalu tidak memengaruhi baris, jadi tidak ikut dihitung.
     */
    public static function of(AnalyticsPrincipal $principal, string $dataset, ?string $policyCode): string
    {
        return 'sha256:'.hash('sha256', json_encode([
            'v' => self::VERSION,
            'tenant' => $principal->tenantId(),
            'policy' => $policyCode === null ? null : [$policyCode, self::grants($principal->policyScope($policyCode))],
            'personal_data' => $principal->mayUsePersonalData(),
            'locked' => self::lockedFilters($principal->lockedFilters($dataset)),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Setiap hibah menjadi teks JSON-nya dengan unit terurut, lalu daftar teks itu diurutkan. Semua urutan
     * memakai perbandingan teks (`SORT_STRING`): perbandingan bawaan PHP membaca id yang kebetulan berbentuk
     * angka sebagai angka, dan urutannya tidak lagi pasti.
     *
     * @param  array{all: bool, scope_grants: list<array{legal_entity_id: ?string, operating_unit_ids: list<string>}>}  $scope
     * @return 'all'|list<string>
     */
    private static function grants(array $scope): string|array
    {
        if ($scope['all']) {
            return 'all';
        }

        $grants = [];
        foreach ($scope['scope_grants'] as $grant) {
            $units = $grant['operating_unit_ids'];
            sort($units, SORT_STRING);
            $grants[] = json_encode([$grant['legal_entity_id'], $units], JSON_THROW_ON_ERROR);
        }
        sort($grants, SORT_STRING);

        return $grants;
    }

    /**
     * @param  array<string, string|list<string>>  $filters
     * @return array<string, string|list<string>>
     */
    private static function lockedFilters(array $filters): array
    {
        ksort($filters, SORT_STRING);
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                sort($value, SORT_STRING);
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
