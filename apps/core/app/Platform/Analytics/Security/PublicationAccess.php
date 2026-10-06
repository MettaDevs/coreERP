<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Security;

use App\Platform\Access\Support\CorePermissions;
use App\Platform\Access\Support\CoreSecurityCatalog;
use App\Platform\Analytics\External\PublicationErrors;
use App\Platform\Analytics\Models\Publication;
use App\Platform\Analytics\Query\AnalyticsQueryException;
use App\Platform\Tenant\Models\TenantMembership;

/**
 * Pemeriksaan ulang pemilik publikasi pada **setiap** permintaan (`docs/todo/analitik/keamanan.md` bagian
 * *Principal*, butir 15.3): keanggotaannya di tenant publikasi masih aktif dan ia masih memegang hak publikasi
 * (`core.analytics.publication.update`). Yang gagal membuat publikasinya tertahan — 403
 * `analytics.publication_suspended` — sampai pemegang hak publikasi lain mengambil alihnya. Hak publikasi tidak
 * boleh hidup lebih lama daripada pembuatnya.
 *
 * Permission baca dataset pemilik diperiksa sesudahnya oleh `DatasetAccess` lewat principal yang dibuat di sini,
 * pada jalur yang sama dengan setiap query; pembaca publikasi menerjemahkan penolakannya menjadi tertahan juga.
 *
 * Tidak ada yang diingat antarpermintaan. `CorePermissions` diambil dari container **pada setiap pemeriksaan**,
 * bukan disuntikkan sekali: kelas ini disuntikkan ke controller, dan router menyimpan instance controller di
 * rutenya, sehingga instance yang disuntikkan dapat hidup lebih lama daripada satu permintaan — bersama ingatan
 * permission pemilik dari permintaan sebelumnya. Ikatan `scoped()` baru segar bila diminta ulang.
 */
final class PublicationAccess
{
    /**
     * Principal publikasi bila pemiliknya masih berhak. `$clientId` klien integrasi yang membaca, untuk log.
     *
     * @throws AnalyticsQueryException `analytics.publication_suspended`
     */
    public function principal(Publication $publication, ?string $clientId = null): PublicationPrincipal
    {
        $owner = $this->owner($publication);
        if ($owner === null) {
            throw PublicationErrors::suspended();
        }

        return PublicationPrincipal::make($publication, $owner, $clientId);
    }

    /** Pemilik masih anggota aktif tenant publikasi dan masih memegang hak publikasi. */
    public function ownerStillEntitled(Publication $publication): bool
    {
        return $this->owner($publication) !== null;
    }

    /** Keanggotaan aktif pemilik yang masih memegang hak publikasi, atau null. */
    private function owner(Publication $publication): ?TenantMembership
    {
        $membership = TenantMembership::query()
            ->where('tenant_id', $publication->tenant_id)
            ->where('user_id', $publication->owner_user_id)
            ->where('status', 'active')
            ->first();

        return app(CorePermissions::class)->allows($membership, CoreSecurityCatalog::ANALYTICS_PUBLICATION_UPDATE) ? $membership : null;
    }
}
