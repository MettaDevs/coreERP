<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Events;

/**
 * Module yang dibeli tenant baru sudah selesai dipasang di lingkungan pertamanya.
 *
 * Event internal Core. Dikirim sinkron sesudah commit pendaftaran, di titik yang sama dengan
 * pemanggilan langsung sebelumnya, dan hanya bila pemasangan memang berjalan di server ini.
 */
final class TenantModulesInstalled
{
    public function __construct(public readonly string $tenantId) {}
}
