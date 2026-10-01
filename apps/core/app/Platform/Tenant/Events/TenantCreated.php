<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Events;

/**
 * Baris tenant baru saja ditulis, di dalam transaksi pendaftarannya.
 *
 * Event internal Core, bukan kontrak module: ia ada supaya Foundation dapat menyiapkan data awal
 * tenant tanpa Platform menyebut Foundation. Dikirim sinkron di titik yang sama dengan pemanggilan
 * langsung sebelumnya, jadi listener ikut transaksi pendaftaran — gagal di listener berarti tenant
 * tidak pernah lahir. Jangan diganti `TenantProvisioned`: itu dikirim per module sesudah commit.
 */
final class TenantCreated
{
    public function __construct(public readonly string $tenantId) {}
}
