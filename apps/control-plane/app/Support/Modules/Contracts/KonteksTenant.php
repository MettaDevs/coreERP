<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Tenant dan organisasi yang sedang aktif pada permintaan ini.
 *
 * Antarmuka ini tidak ada pada rencana awal. Ia ditambahkan karena pemetaan layanan Core
 * disusun dari tiga belas endpoint HTTP, dan penyelesaian tenant tidak pernah lewat HTTP —
 * app lama membacanya dari token. Padahal ini hal **pertama** yang dibutuhkan setiap module:
 * tanpa tenant aktif, tidak ada satu pun query yang boleh dijalankan.
 */
interface KonteksTenant
{
    /** Melempar bila tidak ada tenant aktif. Tidak pernah mengembalikan tebakan. */
    public function tenantId(): string;

    public function legalEntityId(): ?string;

    public function orgUnitId(): ?string;
}
