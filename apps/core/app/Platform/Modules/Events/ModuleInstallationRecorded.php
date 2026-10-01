<?php

declare(strict_types=1);

namespace App\Platform\Modules\Events;

/**
 * Migration module sudah berjalan dan catatan pemasangannya sudah ditulis; data awalnya belum.
 *
 * Event internal Core. Dikirim sinkron di dalam koneksi lingkungan yang sedang dipasangi, sebelum
 * seed module, karena seed menerbitkan nomor sungguhan dan listener-nya menyiapkan urutan nomor.
 */
final class ModuleInstallationRecorded
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $moduleId,
    ) {}
}
