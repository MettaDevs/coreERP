<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;
use App\Platform\Analytics\Security\PersonalDataGate;
use Illuminate\Container\Attributes\Bind;

/**
 * Titik panggil gerbang data pribadi (area 4) dari {@see QueryValidator}: semua kolom dataset yang dipakai
 * sebuah query, sesudah terbukti dikenal dataset dan sebelum ada SQL apa pun.
 *
 * Yang menjawab "boleh atau tidak" adalah implementasinya, dan implementasinya milik area 4
 * (`Security\PersonalDataGate`, dari klasifikasi kolom dan hak principal). Antarmuka ini hanya menetapkan
 * apa yang dilaporkan validator kepadanya, supaya area 4 tidak perlu membaca ulang bentuk query:
 *
 * - Kolom sebagai pengelompok (`dimensions.N`), saringan (`filters.<kolom>`), dan kolom rentang waktu
 *   (`time_range.field`, termasuk bawaan dataset bila query tidak menyebutnya).
 * - Urutan tidak dilaporkan terpisah: `sort` hanya boleh memakai kunci yang sudah dipilih sebagai
 *   pengelompok atau nilai, jadi kolom tertutup tidak dapat masuk lewat urutan tanpa lebih dulu lolos
 *   sebagai pengelompok.
 * - Measure terpilih (`measures.N`, `measures.N.where.<kolom>`): kolom bahannya bila ia field dataset, dan
 *   field saringan tetapnya (area 4). `max(nama_pasien)` memulangkan nama orang, dan katalog yang
 *   menyembunyikan measure itu tidak cukup bila kuncinya masih dapat diketik.
 *
 * Gerbang yang menolak melempar {@see AnalyticsQueryException} (403 `analytics.field_personal_data`,
 * path salah satu kunci `$uses`) dan tidak pernah memulangkan "boleh" karena tidak tahu.
 *
 * Implementasinya {@see PersonalDataGate} (area 4), diikat di container lewat atribut di bawah, dan
 * parameter `QueryValidator` tidak opsional: query tidak punya jalur yang melewatinya.
 */
#[Bind(PersonalDataGate::class)]
interface FieldUseGate
{
    /**
     * @param  array<string, string>  $uses  path bagian query → kunci field yang dipakai di sana
     *
     * @throws AnalyticsQueryException
     */
    public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void;
}
