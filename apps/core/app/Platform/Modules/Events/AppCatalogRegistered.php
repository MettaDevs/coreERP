<?php

declare(strict_types=1);

namespace App\Platform\Modules\Events;

/**
 * Katalog sebuah app selesai didaftarkan dan transaksinya sudah commit.
 *
 * Event internal Core. Dikirim sinkron, sebelum role Owner setiap tenant disamakan.
 */
final class AppCatalogRegistered
{
    public function __construct(public readonly string $appId) {}
}
