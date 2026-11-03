<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Events;

/**
 * Baris tenant baru saja ditulis, di dalam transaksi pendaftarannya.
 *
 * Fakta metadata pusat. Penyiapan data bisnis memakai ErpTenantInitialized agar tenant-only
 * tidak membuat data ERP sebagai efek samping.
 */
final class TenantCreated
{
    public function __construct(public readonly string $tenantId) {}
}
