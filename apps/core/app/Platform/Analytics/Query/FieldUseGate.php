<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

use App\Platform\Analytics\Datasets\CompiledDataset;
use App\Platform\Analytics\Security\AnalyticsPrincipal;

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
 * - Kolom yang menjadi bahan measure bukan kolom yang dipakai query ini, jadi tidak dilaporkan; measure
 *   dijaga pada tingkat dataset oleh area 4.
 *
 * Gerbang yang menolak melempar {@see AnalyticsQueryException} (403 `analytics.field_personal_data`,
 * path salah satu kunci `$uses`) dan tidak pernah memulangkan "boleh" karena tidak tahu.
 *
 * **Belum ada implementasinya.** Selama area 4 belum mengikatnya, `QueryValidator` tidak memanggil apa
 * pun, yaitu keadaan yang sama dengan kerangka berjalan: tidak ada pemeriksaan data pribadi di jalur
 * ini, dan dataset yang terdaftar sekarang tidak menawarkan kolom data pribadi. Area 4 mengikat
 * antarmuka ini di container dan menjadikan parameter validator tidak lagi opsional; dari saat itu
 * query tidak punya jalur yang melewatinya.
 */
interface FieldUseGate
{
    /**
     * @param  array<string, string>  $uses  path bagian query → kunci field yang dipakai di sana
     *
     * @throws AnalyticsQueryException
     */
    public function assertUsable(CompiledDataset $dataset, AnalyticsPrincipal $principal, array $uses): void;
}
