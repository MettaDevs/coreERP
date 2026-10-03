<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Satu dataset analitik milik module: tabel yang boleh dianalisis, field yang ditawarkan, measure
 * yang sah dijumlah, dan kolom yang menegakkan kebijakan data. Padanan query object Business
 * Central bertipe API dan aggregate measurement F&O.
 *
 * Core membaca tabelnya langsung (keputusan pemilik produk, 3 Oktober 2026), tetapi hanya lewat
 * definisi ini: nama tabel dan kolom tidak pernah ditulis di Core.
 */
interface Dataset
{
    /** Id module pemilik, sama dengan `id` pada `app.yaml`. Dataset dari module yang tidak terpasang tidak ditawarkan. */
    public function moduleId(): string;

    /**
     * Definisi dataset. Dipanggil registry sekali per proses lalu dibekukan. Tidak boleh membaca
     * database, sesi, atau konteks permintaan: definisi sama untuk setiap tenant dan pengguna.
     */
    public function definition(): DatasetDefinition;
}
