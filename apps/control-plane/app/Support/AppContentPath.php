<?php

namespace App\Support;

use RuntimeException;

/**
 * Menurunkan path konten UI sebuah app dari pasangan (app, placement).
 *
 * Path ini bukan nilai yang didaftarkan app maupun disimpan di database. Ia
 * diturunkan agar tidak ada nilai yang bisa basi, dan agar dua placement dari
 * app yang sama — pooled shard kedua, atau silo milik satu tenant — tidak
 * pernah memperoleh path yang sama. Placement adalah unit silo/pool, jadi
 * placement pula yang memberi identitas pada runtime UI-nya.
 *
 * Prefix `apps-content` sengaja berbeda segmen dari route host `apps/{app}`,
 * sehingga iframe tidak mungkin memuat ulang halaman host-nya sendiri.
 */
final class AppContentPath
{
    private const IDENTIFIER = '/^[a-z0-9][a-z0-9-]*$/';

    public const PREFIX = '/apps-content/';

    // KEPUTUSAN TERTUNDA — cara mengalamati tenant.
    //
    // Hari ini seluruh tenant berbagi satu host shell dan dibedakan lewat path.
    // Apakah nanti kita memakai subdomain per tenant, custom domain milik
    // pelanggan, atau tetap path-only, belum diputuskan. Lihat catatan
    // "Routing UI per placement" pada docs/dev/01-grand-design.md.
    //
    // Path relatif membuat ketiganya tetap terbuka: ia mewarisi host mana pun
    // tempat shell disajikan, jadi tidak ada baris database yang perlu ditulis
    // ulang saat keputusan itu diambil. Bila kita pindah ke URL absolut,
    // method ini dan perhitungan origin pada resources/js/pages/apps/host.tsx
    // adalah satu-satunya dua tempat yang perlu berubah.
    public static function for(string $appId, string $placement): string
    {
        self::guard($appId, 80, 'app');
        self::guard($placement, 120, 'placement');

        return self::PREFIX.$placement.'/'.$appId.'/';
    }

    private static function guard(string $value, int $max, string $label): void
    {
        if ($value === '' || strlen($value) > $max || preg_match(self::IDENTIFIER, $value) !== 1) {
            throw new RuntimeException("Identifier {$label} tidak valid untuk path konten app.");
        }
    }
}
