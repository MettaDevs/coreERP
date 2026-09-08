<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

use App\Support\Modules\TenantScope;
use Illuminate\Database\Seeder;

/**
 * Induk seeder data awal module.
 *
 * Seeder module berjalan saat pemasangan, bukan saat melayani permintaan, jadi ia tidak bisa
 * menanyakan tenant lewat `KonteksTenant` — tidak ada permintaan yang sedang dilayani. Yang
 * dipakainya adalah tenant yang sedang dipasangi, dan pemasang menaruhnya di wadah selama
 * seed berlangsung.
 *
 * Kelas ini ada untuk alasan yang sama dengan `MilikTenant`: supaya aturan batas tetap
 * berbunyi satu kalimat, yaitu module hanya boleh menyebut `App\Support\Modules\Contracts`.
 */
abstract class SeederModule extends Seeder
{
    /** Melempar bila dipanggil di luar pemasangan module, bukan menebak tenant. */
    protected function tenantId(): string
    {
        return TenantScope::tenantAktif();
    }
}
